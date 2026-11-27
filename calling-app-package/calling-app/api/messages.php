<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$agent = requireAuth();
$pdo = getDb();
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$mode = $_GET['mode'] ?? ($input['mode'] ?? 'group_chat');

if ($mode === 'group_chat') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $message = trim((string)($input['message'] ?? ''));
        if ($message === '' || strlen($message) > 2000) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Message must be between 1 and 2000 characters.']);
            exit;
        }
        $stmt = $pdo->prepare('INSERT INTO group_messages (room_name, sender_email, sender_ext, message_body) VALUES ("solarooof-team", ?, ?, ?)');
        $stmt->execute([$agent['email'], $agent['sip_extension'], $message]);
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }

    $rows = $pdo->query('SELECT sender_email, sender_ext, message_body, created_at FROM group_messages ORDER BY id DESC LIMIT 40')->fetchAll();
    echo json_encode(['ok' => true, 'messages' => array_reverse($rows)]);
    exit;
}

if ($mode === 'customer_dispatch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $channel = ($input['channel'] ?? 'sms') === 'whatsapp' ? 'whatsapp' : 'sms';
    $recipient = trim((string)($input['recipient'] ?? ''));
    $body = trim((string)($input['body'] ?? ''));
    if ($recipient === '' || strlen($recipient) > 64 || $body === '' || strlen($body) > 2000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Provide a valid recipient and message (up to 2000 characters).']);
        exit;
    }
    $stmt = $pdo->prepare('INSERT INTO customer_dispatches (agent_id, channel, recipient, body, status) VALUES (?, ?, ?, ?, "queued")');
    $stmt->execute([$agent['id'], $channel, $recipient, $body]);
    echo json_encode(['ok' => true, 'message' => strtoupper($channel) . ' message queued in the dispatch log.']);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown message mode or unsupported method.']);
