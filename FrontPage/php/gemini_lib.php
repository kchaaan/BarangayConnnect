<?php
/**
 * Google Gemini API client (standard REST generateContent).
 */
require_once __DIR__ . '/gemini_config.php';

/**
 * @return array{ok:bool,reply:?string,error:?string,model:?string}
 */
function bc_gemini_generate_reply(string $systemPrompt, string $userMessage, array $history = []): array
{
    if (!bc_gemini_is_configured()) {
        return ['ok' => false, 'reply' => null, 'error' => 'Gemini is not configured', 'model' => null];
    }

    $apiKey = trim((string) BC_GEMINI_API_KEY);
    $model = trim((string) BC_GEMINI_MODEL);
    if ($model === '') {
        $model = 'gemini-2.0-flash';
    }

    $contents = [];
    foreach ($history as $item) {
        $role = (string) ($item['role'] ?? '');
        $text = trim((string) ($item['content'] ?? ''));
        if ($text === '') {
            continue;
        }
        if ($role === 'user') {
            $contents[] = ['role' => 'user', 'parts' => [['text' => $text]]];
        } elseif ($role === 'bot' || $role === 'model') {
            $contents[] = ['role' => 'model', 'parts' => [['text' => $text]]];
        }
    }

    $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

    $payload = [
        'systemInstruction' => [
            'parts' => [['text' => $systemPrompt]],
        ],
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 1024,
        ],
    ];

    $modelsToTry = array_values(array_unique([$model, 'gemini-2.5-flash', 'gemini-2.0-flash']));
    $lastError = 'Gemini request failed';

    foreach ($modelsToTry as $tryModel) {
        $result = bc_gemini_post_generate($apiKey, $tryModel, $payload);
        if ($result['ok']) {
            $result['model'] = $tryModel;
            return $result;
        }
        $lastError = (string) ($result['error'] ?? $lastError);
        if (strpos(strtolower($lastError), 'not found') === false
            && strpos(strtolower($lastError), '404') === false) {
            break;
        }
    }

    return ['ok' => false, 'reply' => null, 'error' => $lastError, 'model' => null];
}

/**
 * @param array<string, mixed> $payload
 * @return array{ok:bool,reply:?string,error:?string}
 */
function bc_gemini_post_generate(string $apiKey, string $model, array $payload): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'reply' => null, 'error' => 'PHP curl extension is required for Gemini'];
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model) . ':generateContent';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'reply' => null, 'error' => $curlError ?: 'Network error calling Gemini'];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'reply' => null, 'error' => 'Invalid JSON from Gemini'];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $msg = (string) ($data['error']['message'] ?? ('Gemini HTTP ' . $httpCode));
        return ['ok' => false, 'reply' => null, 'error' => $msg];
    }

    $text = '';
    $candidates = $data['candidates'] ?? [];
    if (is_array($candidates) && isset($candidates[0]['content']['parts'][0]['text'])) {
        $text = trim((string) $candidates[0]['content']['parts'][0]['text']);
    }

    if ($text === '') {
        return ['ok' => false, 'reply' => null, 'error' => 'Gemini returned an empty response'];
    }

    return ['ok' => true, 'reply' => $text, 'error' => null];
}

function bc_gemini_chatbot_system_prompt(mysqli $conn, ?array $session = null): string
{
    $role = (string) ($session['user_role'] ?? 'guest');
    $name = trim((string) ($session['submitter_name'] ?? ''));

    $emergency = bc_gemini_context_emergency($conn);
    $announcements = bc_gemini_context_announcements($conn);

    $roleLine = 'You are assisting a ' . $role . ' user of BarangayConnect (Barangay Tambo, Parañaque City).';
    if ($name !== '') {
        $roleLine .= ' The user name is ' . $name . '.';
    }

    return implode("\n", [
        $roleLine,
        'You are a warm, friendly barangay front-desk assistant — sound like a real person helping a neighbor, not a robot.',
        'Use natural phrases like "Sure!", "Great question!", "Happy to help!", and short friendly sentences.',
        'Answer clearly in plain English. You may use **bold** for emphasis.',
        'When listing multiple options or topics, ALWAYS use a numbered list (1. 2. 3.) — never bullet points.',
        'Tell users they may reply with just a number OR type their concern in their own words.',
        'Only answer about BarangayConnect services: registration, requests, reports, announcements, emergency, transparency, profile, feedback, and admin portal basics.',
        'If you do not know something or it is urgent/emergency, kindly tell the user to contact the barangay hall or use the Emergency section.',
        'Do not invent phone numbers, policies, or announcements not listed below.',
        '',
        'Standard numbered menu (use these labels when showing options):',
        '1. BarangayConnect overview',
        '2. Registration & profile',
        '3. Document requests',
        '4. Reports & complaints',
        '5. Tracking status',
        '6. Announcements',
        '7. Emergency hotlines',
        '8. Transparency & budget',
        '9. Security & privacy',
        '',
        'Live emergency hotlines:',
        $emergency,
        '',
        'Latest published announcements:',
        $announcements,
    ]);
}

function bc_gemini_context_emergency(mysqli $conn): string
{
    require_once __DIR__ . '/emergency_lib.php';
    bc_ensure_emergency_tables($conn);
    $res = $conn->query(
        'SELECT service_name, contact_number
         FROM emergency_contacts
         ORDER BY is_critical DESC, sort_order ASC
         LIMIT 8'
    );
    if (!$res || $res->num_rows === 0) {
        return '(none configured)';
    }
    $lines = [];
    while ($row = $res->fetch_assoc()) {
        $lines[] = '- ' . ($row['service_name'] ?? '') . ': ' . ($row['contact_number'] ?? '');
    }
    return implode("\n", $lines);
}

function bc_gemini_context_announcements(mysqli $conn): string
{
    require_once __DIR__ . '/announcements_lib.php';
    bc_ensure_announcements_table($conn);
    $stmt = $conn->prepare(
        "SELECT title, type, announcement_date
         FROM announcements WHERE status = 'published'
         ORDER BY featured DESC, announcement_date DESC, created_at DESC
         LIMIT 5"
    );
    $stmt->execute();
    $result = $stmt->get_result();
    $lines = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $lines[] = '- ' . ($row['title'] ?? '') . ' (' . ($row['type'] ?? '') . ', ' . ($row['announcement_date'] ?? '') . ')';
        }
    }
    $stmt->close();
    return $lines ? implode("\n", $lines) : '(none published)';
}
