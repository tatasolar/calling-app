<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$agent = requireAuth();
$pdo = getDb();

// Auto-delete shortcut notes older than 15 days
$pdo->exec('DELETE FROM shortcut_notes WHERE expires_at <= NOW()');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT id, customer_ref, note_text, created_at, expires_at FROM shortcut_notes WHERE agent_id = ? ORDER BY created_at DESC');
    $stmt->execute([$agent['id']]);
    echo json_encode(['ok' => true, 'notes' => $stmt->fetchAll()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $noteText = trim($input['note_text'] ?? '');
    $customerRef = trim($input['customer_ref'] ?? '');
    if ($noteText === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Note text is required.']);
        exit;
    }
    $stmt = $pdo->prepare('INSERT INTO shortcut_notes (agent_id, customer_ref, note_text, created_at, expires_at) VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 15 DAY))');
    $stmt->execute([$agent['id'], $customerRef !== '' ? $customerRef : null, $noteText]);
    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('DELETE FROM shortcut_notes WHERE id = ? AND agent_id = ?');
    $stmt->execute([$id, $agent['id']]);
    echo json_encode(['ok' => true]);
    exit;
}