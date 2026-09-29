<?php
/**
 * BarangayConnect chatbot — FAQ intents + live data (emergency, announcements).
 */
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/emergency_lib.php';
require_once __DIR__ . '/announcements_lib.php';
require_once __DIR__ . '/gemini_lib.php';

function bc_ensure_chatbot_tables(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS chatbot_sessions (
        session_id VARCHAR(64) PRIMARY KEY,
        user_role VARCHAR(32) NOT NULL DEFAULT 'guest',
        resident_user_id INT NULL DEFAULT NULL,
        submitter_name VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_cb_sess_role (user_role),
        INDEX idx_cb_sess_resident (resident_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS chatbot_messages (
        message_id INT AUTO_INCREMENT PRIMARY KEY,
        session_id VARCHAR(64) NOT NULL,
        role VARCHAR(16) NOT NULL,
        content TEXT NOT NULL,
        intent VARCHAR(64) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cb_msg_session (session_id),
        INDEX idx_cb_msg_created (created_at),
        CONSTRAINT fk_cb_msg_session
            FOREIGN KEY (session_id) REFERENCES chatbot_sessions(session_id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function bc_chatbot_new_session_id(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * @return array<string, mixed>|null
 */
function bc_chatbot_session_exists(mysqli $conn, string $sessionId): ?array
{
    $stmt = $conn->prepare(
        'SELECT session_id, user_role, resident_user_id, submitter_name, created_at
         FROM chatbot_sessions WHERE session_id = ? LIMIT 1'
    );
    $stmt->bind_param('s', $sessionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

/**
 * @param array<string, mixed> $meta
 * @return array<string, mixed>
 */
function bc_chatbot_create_session(mysqli $conn, array $meta = []): array
{
    $sessionId = bc_chatbot_new_session_id();
    $role = trim((string) ($meta['userRole'] ?? $meta['user_role'] ?? 'guest'));
    if ($role === '') {
        $role = 'guest';
    }
    $residentId = (int) ($meta['residentUserId'] ?? $meta['resident_user_id'] ?? 0);
    $name = trim((string) ($meta['submitterName'] ?? $meta['submitter_name'] ?? ''));

    if ($residentId > 0) {
        $stmt = $conn->prepare(
            'INSERT INTO chatbot_sessions (session_id, user_role, resident_user_id, submitter_name)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('ssis', $sessionId, $role, $residentId, $name);
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO chatbot_sessions (session_id, user_role, resident_user_id, submitter_name)
             VALUES (?, ?, NULL, ?)'
        );
        $stmt->bind_param('sss', $sessionId, $role, $name);
    }
    $stmt->execute();
    $stmt->close();

    return [
        'sessionId' => $sessionId,
        'userRole' => $role,
        'residentUserId' => $residentId > 0 ? $residentId : 0,
        'submitterName' => $name,
    ];
}

function bc_chatbot_save_message(
    mysqli $conn,
    string $sessionId,
    string $role,
    string $content,
    ?string $intent = null
): int
{
    $stmt = $conn->prepare(
        'INSERT INTO chatbot_messages (session_id, role, content, intent) VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('ssss', $sessionId, $role, $content, $intent);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

/**
 * @return list<array<string, mixed>>
 */
function bc_chatbot_fetch_history(mysqli $conn, string $sessionId, int $limit = 50): array
{
    $limit = min(100, max(1, $limit));
    $stmt = $conn->prepare(
        "SELECT message_id, session_id, role, content, intent, created_at
         FROM chatbot_messages
         WHERE session_id = ?
         ORDER BY created_at ASC
         LIMIT ?"
    );
    $stmt->bind_param('si', $sessionId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $out = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $ts = strtotime((string) ($row['created_at'] ?? ''));
            $out[] = [
                'id' => (int) ($row['message_id'] ?? 0),
                'role' => (string) ($row['role'] ?? ''),
                'content' => (string) ($row['content'] ?? ''),
                'intent' => (string) ($row['intent'] ?? ''),
                'createdAt' => $ts ? date('c', $ts) : null,
            ];
        }
    }
    $stmt->close();
    return $out;
}

/**
 * @return array<int, array{label:string,intent:string,query:string}>
 */
function bc_chatbot_menu_items(): array
{
    return [
        1 => ['label' => 'BarangayConnect overview', 'intent' => 'about', 'query' => 'What is BarangayConnect?'],
        2 => ['label' => 'Registration & profile', 'intent' => 'registration', 'query' => 'How does registration and updating my profile work?'],
        3 => ['label' => 'Document requests', 'intent' => 'requests', 'query' => 'How do I request barangay documents?'],
        4 => ['label' => 'Reports & complaints', 'intent' => 'reports', 'query' => 'How do I submit a report or complaint?'],
        5 => ['label' => 'Tracking status', 'intent' => 'track_status', 'query' => 'How can I track my request or report status?'],
        6 => ['label' => 'Announcements', 'intent' => 'announcements', 'query' => 'What are the latest announcements?'],
        7 => ['label' => 'Emergency hotlines', 'intent' => 'emergency', 'query' => 'What are the emergency hotlines?'],
        8 => ['label' => 'Transparency & budget', 'intent' => 'transparency', 'query' => 'What is the Transparency section for?'],
        9 => ['label' => 'Security & privacy', 'intent' => 'security', 'query' => 'Is my information secure in BarangayConnect?'],
    ];
}

/**
 * Menu chips + End this chat — shown only when the concern is resolved.
 *
 * @return list<string>
 */
function bc_chatbot_closing_chip_suggestions(): array
{
    $out = [];
    foreach (bc_chatbot_menu_items() as $num => $item) {
        $out[] = $num . '. ' . $item['label'];
    }
    $out[] = 'End this chat';
    return $out;
}

function bc_chatbot_is_end_chat_request(string $message): bool
{
    $norm = bc_chatbot_normalize($message);
    $phrases = [
        'end chat',
        'end this chat',
        'close chat',
        'stop chat',
        'end conversation',
        'tapusin ang chat',
    ];
    foreach ($phrases as $phrase) {
        if ($norm === $phrase || strpos($norm, $phrase) !== false) {
            return true;
        }
    }
    return false;
}

function bc_chatbot_is_resolved_signal(string $message): bool
{
    $norm = bc_chatbot_normalize($message);
    $phrases = [
        'thank',
        'thanks',
        'salamat',
        'thank you',
        'maraming salamat',
        'nothing else',
        'no more questions',
        'no further questions',
        'that s all',
        'thats all',
        'that is all',
        'all good',
        'all set',
        'im good',
        'i m good',
        'ok na',
        'wala na',
        'no more',
        'that s it',
        'were good',
        'we re good',
        'goodbye',
        'bye',
        'done',
    ];
    foreach ($phrases as $phrase) {
        if ($norm === $phrase || strpos($norm, $phrase) !== false) {
            return true;
        }
    }
    return false;
}

function bc_chatbot_closing_reply_text(?array $session = null): string
{
    $greet = bc_chatbot_personal_greeting($session);
    return $greet . "I'm glad I could help with your concern!\n\n"
        . "If you need anything else, you can pick another topic below or type **menu**.\n\n"
        . "When you're all set and have no further questions, tap **End this chat** below.";
}

function bc_chatbot_end_chat_reply_text(?array $session = null): string
{
    $greet = bc_chatbot_personal_greeting($session);
    return $greet . "Thank you for chatting with BarangayConnect today. Take care, and feel free to come back anytime you need help!";
}

/**
 * @param array<string, mixed> $reply
 * @return array<string, mixed>
 */
function bc_chatbot_with_closing_chips(array $reply): array
{
    $reply['suggestions'] = bc_chatbot_closing_chip_suggestions();
    $reply['showClosingChips'] = true;
    return $reply;
}

function bc_chatbot_personal_greeting(?array $session = null): string
{
    $name = trim((string) ($session['submitter_name'] ?? ''));
    if ($name !== '') {
        return 'Hi ' . $name . '! ';
    }
    return 'Hi there! ';
}

function bc_chatbot_numbered_menu_text(?array $session = null): string
{
    $greet = bc_chatbot_personal_greeting($session);
    $lines = [
        $greet . "I'm glad you reached out — I'm here to help you with BarangayConnect.",
        '',
        'Here are the topics I can walk you through:',
        '',
    ];

    foreach (bc_chatbot_menu_items() as $num => $item) {
        $lines[] = $num . '. ' . $item['label'];
    }

    $lines[] = '';
    $lines[] = 'You can reply with **just the number** (for example, type **3**), or describe your concern in your own words — either way works!';
    $lines[] = '';
    $lines[] = 'What would you like help with today?';

    return implode("\n", $lines);
}

function bc_chatbot_welcome_text(?array $session = null): string
{
    $greet = bc_chatbot_personal_greeting($session);
    $role = (string) ($session['user_role'] ?? $session['userRole'] ?? 'guest');

    $lines = [
        $greet . 'Thanks for stopping by. I\'m your BarangayConnect assistant, and I\'m happy to help.',
        '',
        'Feel free to ask me anything — like how to submit a report, request documents, or find emergency hotlines.',
        '',
        'Want a quick guide? Type **menu** or **help** and I\'ll show you numbered options you can pick from.',
        '',
    ];

    if (in_array($role, ['admin', 'superadmin'], true)) {
        $lines[] = 'Since you\'re on the admin side, I can also guide you with dashboard, users, and feedback tools.';
        $lines[] = '';
    }

    $lines[] = 'How can I assist you today?';

    return implode("\n", $lines);
}

function bc_chatbot_is_menu_request(string $message): bool
{
    $norm = bc_chatbot_normalize($message);
    $menuWords = ['help', 'menu', 'options', 'what can you do', 'commands', 'topics', 'concerns'];
    foreach ($menuWords as $word) {
        if ($norm === $word || strpos($norm, $word) !== false) {
            return true;
        }
    }
    return false;
}

function bc_chatbot_expand_number_input(string $message): ?string
{
    $trim = trim($message);
    if (!preg_match('/^#?(\d+)$/', $trim, $matches)) {
        return null;
    }

    $num = (int) $matches[1];
    $items = bc_chatbot_menu_items();
    if (!isset($items[$num])) {
        return null;
    }

    return $items[$num]['query'];
}

function bc_chatbot_prepare_message(string $message): string
{
    $expanded = bc_chatbot_expand_number_input($message);
    return $expanded ?? trim($message);
}

/**
 * @return list<array{intent:string,keywords:list<string>,reply:string,dynamic?:string}>
 */
function bc_chatbot_intents(string $userRole): array
{
    $common = [
        [
            'intent' => 'greeting',
            'keywords' => ['hello', 'hi', 'hey', 'good morning', 'good afternoon', 'good evening', 'kumusta', 'kamusta'],
            'reply' => '', // uses bc_chatbot_welcome_text()
        ],
        [
            'intent' => 'help',
            'keywords' => ['help', 'menu', 'options', 'what can you do', 'commands', 'topics'],
            'reply' => '', // uses bc_chatbot_numbered_menu_text()
        ],
        [
            'intent' => 'thanks',
            'keywords' => ['thank', 'thanks', 'salamat', 'maraming salamat', 'thank you'],
            'reply' => '', // uses bc_chatbot_closing_reply_text()
        ],
        [
            'intent' => 'about',
            'keywords' => ['what is barangayconnect', 'about barangayconnect', 'barangayconnect', 'what is this system', 'ano ang barangayconnect'],
            'reply' => 'Great question! BarangayConnect is your barangay\'s online hub — you can check announcements, submit requests and reports, view transparency records, and find emergency info, all in one place.',
        ],
        [
            'intent' => 'who_can_use',
            'keywords' => ['who can use', 'who can access', 'sino pwede', 'resident admin', 'users'],
            'reply' => 'The system is for residents and barangay administrators. Residents submit requests and reports; administrators manage records, documents, and community information.',
        ],
        [
            'intent' => 'registration',
            'keywords' => ['register', 'registration', 'sign up', 'signup', 'after registering', 'new account', 'activate'],
            'reply' => 'After successful registration, your resident account is activated and you are taken to the resident homepage. Use accurate information so barangay records stay correct.',
        ],
        [
            'intent' => 'requests',
            'keywords' => ['request', 'document', 'clearance', 'certificate', 'permit', 'endorsement', 'barangay clearance'],
            'reply' => 'Go to the **Requests** section to request barangay documents such as clearance, certificates, permits, and endorsements. Fill out the form and wait for status updates.',
        ],
        [
            'intent' => 'reports',
            'keywords' => ['report', 'complaint', 'issue', 'concern', 'submit report', 'community problem'],
            'reply' => 'Open the **Reports** section, describe the issue or concern in your community, and submit. You can track status updates from your dashboard.',
        ],
        [
            'intent' => 'track_status',
            'keywords' => ['track', 'status', 'pending', 'approved', 'completed', 'follow up', 'progress'],
            'reply' => 'Yes — you can track submitted requests and reports. Check **Requests**, **Reports**, or your **Home** dashboard notifications for pending, approved, or completed status.',
        ],
        [
            'intent' => 'transparency',
            'keywords' => ['transparency', 'budget', 'project', 'fund', 'programs', 'accountability'],
            'reply' => 'The **Transparency** section shows barangay projects, programs, and fund utilization to promote openness and accountability.',
        ],
        [
            'intent' => 'profile',
            'keywords' => ['profile', 'update information', 'personal info', 'edit account', 'settings', 'emergency contact'],
            'reply' => 'Residents can update profile and emergency contact details through **Update Profile** in the settings menu (gear icon at the top).',
        ],
        [
            'intent' => 'security',
            'keywords' => ['secure', 'security', 'privacy', 'safe', 'data protection', 'information secure'],
            'reply' => 'BarangayConnect keeps resident information accessible only to authorized barangay administrators.',
        ],
        [
            'intent' => 'feedback',
            'keywords' => ['feedback', 'suggestion', 'rate', 'satisfied', 'not satisfied', 'comment'],
            'reply' => 'You can send feedback from **FAQ & Feedback** in the resident portal or from the front page feedback section. Admins review submissions in read-only mode.',
        ],
        [
            'intent' => 'announcements',
            'keywords' => ['announcement', 'announcements', 'news', 'update', 'advisory', 'notice'],
            'reply' => '',
            'dynamic' => 'announcements',
        ],
        [
            'intent' => 'emergency',
            'keywords' => ['emergency', 'hotline', 'hotlines', 'contact number', 'ambulance', 'fire', 'police', 'rescue', '911'],
            'reply' => '',
            'dynamic' => 'emergency',
        ],
    ];

    $admin = [
        [
            'intent' => 'admin_dashboard',
            'keywords' => ['admin dashboard', 'dashboard', 'home admin', 'recent activity'],
            'reply' => 'The admin **Home** dashboard shows recent activity, announcements summary, and quick stats. Data refreshes from the database.',
        ],
        [
            'intent' => 'admin_users',
            'keywords' => ['user management', 'manage users', 'residents list', 'admin users'],
            'reply' => 'Use **User Management** to view and manage resident accounts registered in BarangayConnect.',
        ],
        [
            'intent' => 'admin_feedback',
            'keywords' => ['resident feedback', 'view feedback', 'feedback admin'],
            'reply' => 'Open **Feedback** to view resident submissions. Feedback is read-only — you can view details and mark items as read, but not edit or delete them.',
        ],
    ];

    if (in_array($userRole, ['admin', 'superadmin'], true)) {
        return array_merge($common, $admin);
    }

    return $common;
}

function bc_chatbot_normalize(string $text): string
{
    $t = strtolower(trim($text));
    $t = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $t) ?? $t;
    $t = preg_replace('/\s+/', ' ', $t) ?? $t;
    return trim($t);
}

/**
 * @param list<array{intent:string,keywords:list<string>,reply:string,dynamic?:string}> $intents
 * @return array{intent:string,score:int}
 */
function bc_chatbot_match_intent(string $message, array $intents): array
{
    $norm = bc_chatbot_normalize($message);
    if ($norm === '') {
        return ['intent' => 'fallback', 'score' => 0];
    }

    $bestIntent = 'fallback';
    $bestScore = 0;

    foreach ($intents as $item) {
        $score = 0;
        foreach ($item['keywords'] as $keyword) {
            $kw = bc_chatbot_normalize($keyword);
            if ($kw === '') {
                continue;
            }
            if ($norm === $kw) {
                $score += 10;
            } elseif (strpos($norm, $kw) !== false) {
                $score += strlen($kw) >= 8 ? 6 : 4;
            } else {
                $words = explode(' ', $kw);
                $wordHits = 0;
                foreach ($words as $w) {
                    if ($w !== '' && strpos($norm, $w) !== false) {
                        $wordHits++;
                    }
                }
                if ($wordHits > 0 && $wordHits >= count($words)) {
                    $score += 3;
                }
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestIntent = (string) $item['intent'];
        }
    }

    return ['intent' => $bestIntent, 'score' => $bestScore];
}

function bc_chatbot_dynamic_emergency(mysqli $conn): string
{
    bc_ensure_emergency_tables($conn);
    $res = $conn->query(
        'SELECT service_name, contact_number, description
         FROM emergency_contacts
         ORDER BY is_critical DESC, sort_order ASC, service_name ASC
         LIMIT 8'
    );
    if (!$res || $res->num_rows === 0) {
        return 'Emergency hotlines are not configured yet. Please visit the **Emergency** section or contact the barangay hall directly.';
    }

    $lines = ["Sure — here are the emergency hotlines we have on file:\n"];
    while ($row = $res->fetch_assoc()) {
        $name = trim((string) ($row['service_name'] ?? ''));
        $num = trim((string) ($row['contact_number'] ?? ''));
        $desc = trim((string) ($row['description'] ?? ''));
        $line = '• **' . $name . '**: ' . $num;
        if ($desc !== '') {
            $line .= ' — ' . $desc;
        }
        $lines[] = $line;
    }
    $lines[] = "\nFor full procedures, open the **Emergency** section.";
    return implode("\n", $lines);
}

function bc_chatbot_dynamic_announcements(mysqli $conn): string
{
    bc_ensure_announcements_table($conn);
    $stmt = $conn->prepare(
        "SELECT title, description, type, announcement_date
         FROM announcements
         WHERE status = 'published'
         ORDER BY featured DESC, announcement_date DESC, created_at DESC
         LIMIT 5"
    );
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $stmt->close();

    if (!$rows) {
        return 'There are no published announcements right now. Check the **Announcements** section later for updates.';
    }

    $lines = ["Here's what's new from the barangay:\n"];
    foreach ($rows as $row) {
        $title = trim((string) ($row['title'] ?? ''));
        $type = trim((string) ($row['type'] ?? ''));
        $date = trim((string) ($row['announcement_date'] ?? ''));
        $desc = trim((string) ($row['description'] ?? ''));
        if (strlen($desc) > 120) {
            $desc = substr($desc, 0, 117) . '...';
        }
        $meta = [];
        if ($type !== '') {
            $meta[] = ucfirst($type);
        }
        if ($date !== '') {
            $meta[] = $date;
        }
        $line = '• **' . $title . '**';
        if ($meta) {
            $line .= ' (' . implode(' · ', $meta) . ')';
        }
        if ($desc !== '') {
            $line .= "\n  " . $desc;
        }
        $lines[] = $line;
    }
    return implode("\n", $lines);
}

/**
 * @return list<array{role:string,content:string}>
 */
function bc_chatbot_history_for_gemini(mysqli $conn, string $sessionId, int $limit = 12): array
{
    $rows = bc_chatbot_fetch_history($conn, $sessionId, $limit);
    if (count($rows) <= 1) {
        return [];
    }

    $history = array_slice($rows, 0, -1);
    $out = [];
    foreach ($history as $row) {
        $out[] = [
            'role' => (string) ($row['role'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
        ];
    }
    return $out;
}

/**
 * @param array<string, mixed>|null $session
 * @return array{reply:string,intent:string,suggestions:list<string>,provider:string}
 */
function bc_chatbot_generate_reply(
    mysqli $conn,
    string $message,
    ?array $session = null,
    ?string $sessionId = null
): array
{
    $rawMessage = trim($message);
    $message = bc_chatbot_prepare_message($rawMessage);

    if (bc_chatbot_is_end_chat_request($rawMessage)) {
        return [
            'reply' => bc_chatbot_end_chat_reply_text($session),
            'intent' => 'end_chat',
            'suggestions' => [],
            'showClosingChips' => false,
            'endChat' => true,
            'provider' => 'closing',
        ];
    }

    if (bc_chatbot_is_resolved_signal($rawMessage)) {
        return bc_chatbot_with_closing_chips([
            'reply' => bc_chatbot_closing_reply_text($session),
            'intent' => 'resolved',
            'provider' => 'closing',
        ]);
    }

    if (bc_chatbot_is_menu_request($rawMessage)) {
        return [
            'reply' => bc_chatbot_numbered_menu_text($session),
            'intent' => 'help',
            'suggestions' => [],
            'showClosingChips' => false,
            'provider' => 'menu',
        ];
    }

    if (preg_match('/^#?(\d+)$/', $rawMessage) && bc_chatbot_expand_number_input($rawMessage) === null) {
        $greet = bc_chatbot_personal_greeting($session);
        return [
            'reply' => $greet . "I don't have an option for that number yet — please pick **1 to 9**, or type **menu** to see the full list again.",
            'intent' => 'invalid_menu',
            'suggestions' => [],
            'provider' => 'menu',
        ];
    }

    if (bc_gemini_is_configured()) {
        $history = $sessionId ? bc_chatbot_history_for_gemini($conn, $sessionId) : [];
        $systemPrompt = bc_gemini_chatbot_system_prompt($conn, $session);
        $gemini = bc_gemini_generate_reply($systemPrompt, $message, $history);

        if ($gemini['ok'] && !empty($gemini['reply'])) {
            $geminiReply = (string) $gemini['reply'];
            $geminiReply .= "\n\nIs there anything else I can help you with? If you're all set, just say **thank you** or type **end chat**.";
            return [
                'reply' => $geminiReply,
                'intent' => 'gemini',
                'suggestions' => [],
                'showClosingChips' => false,
                'provider' => 'gemini',
            ];
        }

        if (BC_DEBUG && !empty($gemini['error'])) {
            error_log('BarangayConnect Gemini fallback: ' . $gemini['error']);
        }
    }

    $ruleBased = bc_chatbot_generate_rule_reply($conn, $message, $session);
    $ruleBased['provider'] = 'rules';
    return $ruleBased;
}

/**
 * @param array<string, mixed>|null $session
 * @return array{reply:string,intent:string,suggestions:list<string>}
 */
function bc_chatbot_generate_rule_reply(mysqli $conn, string $message, ?array $session = null): array
{
    $role = (string) ($session['user_role'] ?? 'guest');
    $intents = bc_chatbot_intents($role);
    $match = bc_chatbot_match_intent($message, $intents);
    $intent = $match['score'] > 0 ? $match['intent'] : 'fallback';

    $reply = '';

    if ($intent === 'fallback') {
        $greet = bc_chatbot_personal_greeting($session);
        return [
            'reply' => $greet . "Hmm, I'm not quite sure about that one yet — but I'm still here to help!\n\n"
                . "Type **menu** or **help** to see numbered options, or ask me directly about requests, reports, announcements, or emergency hotlines.\n\n"
                . 'For urgent matters, please contact the barangay hall right away.',
            'intent' => 'fallback',
            'suggestions' => [],
        ];
    }

    if ($intent === 'help') {
        return [
            'reply' => bc_chatbot_numbered_menu_text($session),
            'intent' => 'help',
            'suggestions' => [],
            'showClosingChips' => false,
        ];
    }

    if ($intent === 'thanks') {
        return bc_chatbot_with_closing_chips([
            'reply' => bc_chatbot_closing_reply_text($session),
            'intent' => 'thanks',
        ]);
    }

    if ($intent === 'greeting') {
        return [
            'reply' => bc_chatbot_welcome_text($session),
            'intent' => 'greeting',
            'suggestions' => [],
        ];
    }

    foreach ($intents as $item) {
        if ($item['intent'] !== $intent) {
            continue;
        }

        $dynamic = (string) ($item['dynamic'] ?? '');
        if ($dynamic === 'emergency') {
            $reply = bc_chatbot_dynamic_emergency($conn);
        } elseif ($dynamic === 'announcements') {
            $reply = bc_chatbot_dynamic_announcements($conn);
        } else {
            $reply = (string) ($item['reply'] ?? '');
        }
        break;
    }

    if ($reply === '') {
        $reply = 'I\'m having trouble with that right now. Type **menu** or **help** and pick a number, or tell me your concern in your own words.';
    }

    if (in_array($role, ['admin', 'superadmin'], true) && $intent === 'greeting') {
        $reply = bc_chatbot_welcome_text($session);
    }

    $reply .= "\n\nIs there anything else I can help you with? If you're all set, just say **thank you** or type **end chat**.";

    return [
        'reply' => $reply,
        'intent' => $intent,
        'suggestions' => [],
        'showClosingChips' => false,
    ];
}

/**
 * @param array<string, mixed> $meta
 * @return array<string, mixed>
 */
function bc_chatbot_handle_message(mysqli $conn, string $sessionId, string $message, array $meta = []): array
{
    $message = trim($message);
    if ($message === '') {
        return ['success' => false, 'error' => 'message is required'];
    }

    $session = bc_chatbot_session_exists($conn, $sessionId);
    if (!$session) {
        return ['success' => false, 'error' => 'Invalid session'];
    }

    bc_chatbot_save_message($conn, $sessionId, 'user', $message, null);
    $generated = bc_chatbot_generate_reply($conn, $message, $session, $sessionId);
    $botId = bc_chatbot_save_message($conn, $sessionId, 'bot', $generated['reply'], $generated['intent']);

    return [
        'success' => true,
        'sessionId' => $sessionId,
        'messageId' => $botId,
        'reply' => $generated['reply'],
        'intent' => $generated['intent'],
        'suggestions' => $generated['suggestions'] ?? [],
        'showClosingChips' => !empty($generated['showClosingChips']),
        'endChat' => !empty($generated['endChat']),
        'provider' => $generated['provider'] ?? 'rules',
        'geminiEnabled' => bc_gemini_is_configured(),
    ];
}
