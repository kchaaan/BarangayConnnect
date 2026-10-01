<?php
declare(strict_types=1);
require_once __DIR__ . '/supabase_client.php';

bc_sb_session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function bc_captcha_json(int $status, array $data): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $glyphs = [
        'A'=>'01110/10001/10001/11111/10001/10001/10001', 'B'=>'11110/10001/10001/11110/10001/10001/11110',
        'C'=>'01111/10000/10000/10000/10000/10000/01111', 'D'=>'11110/10001/10001/10001/10001/10001/11110',
        'E'=>'11111/10000/10000/11110/10000/10000/11111', 'F'=>'11111/10000/10000/11110/10000/10000/10000',
        'G'=>'01111/10000/10000/10111/10001/10001/01111', 'H'=>'10001/10001/10001/11111/10001/10001/10001',
        'J'=>'00111/00010/00010/00010/10010/10010/01100', 'K'=>'10001/10010/10100/11000/10100/10010/10001',
        'L'=>'10000/10000/10000/10000/10000/10000/11111', 'M'=>'10001/11011/10101/10101/10001/10001/10001',
        'N'=>'10001/11001/10101/10011/10001/10001/10001', 'P'=>'11110/10001/10001/11110/10000/10000/10000',
        'Q'=>'01110/10001/10001/10001/10101/10010/01101', 'R'=>'11110/10001/10001/11110/10100/10010/10001',
        'S'=>'01111/10000/10000/01110/00001/00001/11110', 'T'=>'11111/00100/00100/00100/00100/00100/00100',
        'U'=>'10001/10001/10001/10001/10001/10001/01110', 'V'=>'10001/10001/10001/10001/10001/01010/00100',
        'W'=>'10001/10001/10001/10101/10101/10101/01010', 'X'=>'10001/10001/01010/00100/01010/10001/10001',
        'Y'=>'10001/10001/01010/00100/00100/00100/00100', 'Z'=>'11111/00001/00010/00100/01000/10000/11111',
        '2'=>'01110/10001/00001/00010/00100/01000/11111', '3'=>'11110/00001/00001/01110/00001/00001/11110',
        '4'=>'00010/00110/01010/10010/11111/00010/00010', '5'=>'11111/10000/10000/11110/00001/00001/11110',
        '6'=>'01111/10000/10000/11110/10001/10001/01110', '7'=>'11111/00001/00010/00100/01000/01000/01000',
        '8'=>'01110/10001/10001/01110/10001/10001/01110', '9'=>'01110/10001/10001/01111/00001/00001/11110'
    ];
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    $_SESSION['bc_login_captcha_hash'] = hash('sha256', $code);
    $_SESSION['bc_login_captcha_expires'] = time() + 180;
    $_SESSION['bc_login_captcha_attempts'] = 0;
    unset($_SESSION['bc_login_captcha_token_hash'], $_SESSION['bc_login_captcha_context'], $_SESSION['bc_login_captcha_verified_at']);

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="280" height="76" viewBox="0 0 280 76" role="img" aria-label="CAPTCHA code">';
    $svg .= '<rect width="280" height="76" rx="10" fill="#eef4ee"/>';
    for ($i = 0; $i < 6; $i++) {
        $x1 = random_int(0, 280); $y1 = random_int(0, 76);
        $x2 = random_int(0, 280); $y2 = random_int(0, 76);
        $svg .= '<path d="M'.$x1.' '.$y1.' Q'.random_int(0, 280).' '.random_int(0, 76).' '.$x2.' '.$y2.'" fill="none" stroke="#9aae9b" stroke-opacity=".55" stroke-width="'.random_int(1, 3).'"/>';
    }
    for ($i = 0; $i < 26; $i++) {
        $svg .= '<circle cx="'.random_int(4, 276).'" cy="'.random_int(4, 72).'" r="'.random_int(1, 2).'" fill="#607d62" fill-opacity=".35"/>';
    }
    for ($i = 0; $i < 6; $i++) {
        $x = 12 + ($i * 44) + random_int(-2, 2);
        $y = random_int(16, 22);
        $angle = random_int(-16, 16);
        $path = '';
        $rows = explode('/', $glyphs[$code[$i]]);
        foreach ($rows as $rowIndex => $row) {
            for ($column = 0; $column < 5; $column++) {
                if ($row[$column] === '1') {
                    $px = $column * 5.2;
                    $py = $rowIndex * 5.2;
                    $path .= 'M'.$px.' '.$py.'h4v4h-4z';
                }
            }
        }
        $svg .= '<path d="'.$path.'" transform="translate('.$x.' '.$y.') rotate('.$angle.' 13 18)" fill="#173d2a"/>';
    }
    $svg .= '</svg>';

    header('Content-Type: image/svg+xml; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
    echo $svg;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'verify') {
    bc_captcha_json(405, ['success' => false, 'error' => 'Invalid CAPTCHA request.']);
}

$captchaHash = (string)($_SESSION['bc_login_captcha_hash'] ?? '');
$expires = (int)($_SESSION['bc_login_captcha_expires'] ?? 0);
$answer = strtoupper(trim((string)($_POST['answer'] ?? '')));
if ($captchaHash === '' || $expires < time()) {
    unset($_SESSION['bc_login_captcha_hash'], $_SESSION['bc_login_captcha_expires'], $_SESSION['bc_login_captcha_attempts']);
    bc_captcha_json(400, ['success' => false, 'error' => 'The CAPTCHA has expired. Generate a new code.']);
}

if (strlen($answer) !== 6 || !hash_equals($captchaHash, hash('sha256', $answer))) {
    $_SESSION['bc_login_captcha_attempts'] = (int)($_SESSION['bc_login_captcha_attempts'] ?? 0) + 1;
    if ($_SESSION['bc_login_captcha_attempts'] >= 6) {
        unset($_SESSION['bc_login_captcha_hash'], $_SESSION['bc_login_captcha_expires'], $_SESSION['bc_login_captcha_attempts']);
        bc_captcha_json(429, ['success' => false, 'error' => 'Too many incorrect attempts. Generate a new CAPTCHA.']);
    }
    bc_captcha_json(400, ['success' => false, 'error' => 'The code does not match. Please try again.']);
}

$identifier = strtolower(trim((string)($_POST['identifier'] ?? '')));
if ($identifier === '') {
    bc_captcha_json(400, ['success' => false, 'error' => 'Enter your username first.']);
}

unset($_SESSION['bc_login_captcha_hash'], $_SESSION['bc_login_captcha_expires'], $_SESSION['bc_login_captcha_attempts']);
$token = bin2hex(random_bytes(32));
$_SESSION['bc_login_captcha_token_hash'] = hash('sha256', $token);
$_SESSION['bc_login_captcha_context'] = hash('sha256', $identifier);
$_SESSION['bc_login_captcha_verified_at'] = time();
bc_captcha_json(200, ['success' => true, 'token' => $token]);
