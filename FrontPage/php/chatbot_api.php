<?php
/**
 * Chatbot API (MySQL).
 *
 * POST ?action=start           JSON body (optional userRole, residentUserId, submitterName)
 * POST ?action=chat            JSON body { sessionId, message }
 * GET  ?action=history&sessionId=
 */
require_once __DIR__ . '/chatbot_lib.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function bc_cb_json_error(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function bc_cb_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

$conn = bc_db_connect();
if (!$conn) {
    bc_cb_json_error(500, bc_db_error_response());
}

bc_ensure_chatbot_tables($conn);

$action = trim($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST' && $action === 'start') {
    header('Content-Type: application/json; charset=utf-8');
    $body = bc_cb_read_json_body();
    $session = bc_chatbot_create_session($conn, $body);

    $welcomeSession = [
        'user_role' => $session['userRole'],
        'submitter_name' => $session['submitterName'],
    ];
    $welcome = [
        'reply' => bc_chatbot_welcome_text($welcomeSession),
        'suggestions' => [],
        'provider' => 'welcome',
    ];
    bc_chatbot_save_message($conn, $session['sessionId'], 'bot', $welcome['reply'], 'welcome');

    $conn->close();
    echo json_encode([
        'success' => true,
        'sessionId' => $session['sessionId'],
        'welcome' => $welcome['reply'],
        'suggestions' => $welcome['suggestions'],
        'provider' => $welcome['provider'] ?? 'rules',
        'geminiEnabled' => bc_gemini_is_configured(),
    ]);
    exit;
}

if ($method === 'POST' && $action === 'chat') {
    header('Content-Type: application/json; charset=utf-8');
    $body = bc_cb_read_json_body();
    $sessionId = trim((string) ($body['sessionId'] ?? ''));
    $message = trim((string) ($body['message'] ?? ''));

    if ($sessionId === '') {
        bc_cb_json_error(400, 'sessionId is required');
    }
    if ($message === '') {
        bc_cb_json_error(400, 'message is required');
    }

    $result = bc_chatbot_handle_message($conn, $sessionId, $message);
    $conn->close();

    if (empty($result['success'])) {
        bc_cb_json_error(400, (string) ($result['error'] ?? 'Failed to process message'));
    }

    echo json_encode($result);
    exit;
}

if ($method === 'GET' && $action === 'history') {
    header('Content-Type: application/json; charset=utf-8');
    $sessionId = trim((string) ($_GET['sessionId'] ?? ''));
    if ($sessionId === '') {
        bc_cb_json_error(400, 'sessionId is required');
    }

    $session = bc_chatbot_session_exists($conn, $sessionId);
    if (!$session) {
        bc_cb_json_error(404, 'Session not found');
    }

    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
    $messages = bc_chatbot_fetch_history($conn, $sessionId, $limit);
    $conn->close();

    echo json_encode([
        'success' => true,
        'sessionId' => $sessionId,
        'messages' => $messages,
    ]);
    exit;
}

$conn->close();
bc_cb_json_error(400, 'Unknown action or method');
