<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$pdo = getDb();
$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (($_GET['action'] ?? '') === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $agent = requireAuth();
    $recipient = trim((string)($input['recipient'] ?? ''));
    if (!preg_match('/^[+0-9()\\-\\s]{3,64}$/', $recipient)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Enter a valid customer phone number.']);
        exit;
    }
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare('INSERT INTO call_feedback (agent_id, peer_target, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))');
    $stmt->execute([$agent['id'], $recipient, $tokenHash]);
    echo json_encode(['ok' => true, 'token' => $token]);
    exit;
}

if (($_GET['action'] ?? '') === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($input['token'] ?? '');
    $conduct = filter_var($input['conduct_rating'] ?? null, FILTER_VALIDATE_INT);
    $behavior = filter_var($input['behavior_rating'] ?? null, FILTER_VALIDATE_INT);
    $comments = trim((string)($input['comments'] ?? ''));
    if (strlen($token) < 40 || strlen($token) > 64 || $conduct === false || $conduct < 1 || $conduct > 5 || $behavior === false || $behavior < 1 || $behavior > 5 || strlen($comments) > 1000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Please provide both ratings from 1 to 5 and keep comments under 1000 characters.']);
        exit;
    }
    $stmt = $pdo->prepare('UPDATE call_feedback SET conduct_rating = ?, behavior_rating = ?, comments = ? WHERE token_hash = ? AND expires_at > NOW() AND conduct_rating IS NULL');
    $stmt->execute([$conduct, $behavior, $comments !== '' ? $comments : null, hash('sha256', $token)]);
    if ($stmt->rowCount() !== 1) {
        http_response_code(410);
        echo json_encode(['ok' => false, 'error' => 'This feedback link is invalid, expired, or has already been used.']);
        exit;
    }
    echo json_encode(['ok' => true, 'message' => 'Thank you for sharing your feedback.']);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown feedback action or unsupported method.']);
