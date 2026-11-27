<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$agent = requireAuth();
$pdo = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $upload = $_FILES['audio'] ?? null;
    if (!$upload || $upload['error'] !== UPLOAD_ERR_OK || $upload['size'] < 1 || $upload['size'] > 25 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Recording is missing or exceeds the 25 MB limit.']);
        exit;
    }
    if (!class_exists('finfo')) {
        throw new RuntimeException('The PHP fileinfo extension is required for recording uploads.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    if (!in_array($mime, ['audio/webm', 'video/webm'], true)) {
        http_response_code(415);
        echo json_encode(['ok' => false, 'error' => 'Only WebM call recordings are accepted.']);
        exit;
    }

    $peer = preg_replace('/[^a-zA-Z0-9+_-]/', '', (string)($_POST['peer_target'] ?? '')) ?: 'unknown';
    $type = ($_POST['call_type'] ?? 'direct') === 'group' ? 'group' : 'direct';
    $duration = min(86400, max(0, (int)($_POST['duration_sec'] ?? 0)));
    $fileName = 'rec_' . $agent['id'] . '_' . bin2hex(random_bytes(16)) . '.webm';
    $recordingDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private-recordings';
    if (!is_dir($recordingDirectory) && !mkdir($recordingDirectory, 0700, true) && !is_dir($recordingDirectory)) {
        throw new RuntimeException('Unable to create the private recording directory.');
    }
    if (!move_uploaded_file($upload['tmp_name'], $recordingDirectory . '/' . $fileName)) {
        throw new RuntimeException('Unable to store the uploaded call recording.');
    }

    $stmt = $pdo->prepare('INSERT INTO call_recordings (agent_id, peer_target, call_type, file_name, duration_sec) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$agent['id'], $peer, $type, $fileName, $duration]);
    echo json_encode(['ok' => true, 'file_name' => $fileName]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT id, peer_target, call_type, file_name, duration_sec, created_at FROM call_recordings WHERE agent_id = ? ORDER BY created_at DESC LIMIT 30');
    $stmt->execute([$agent['id']]);
    echo json_encode(['ok' => true, 'recordings' => $stmt->fetchAll()]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
