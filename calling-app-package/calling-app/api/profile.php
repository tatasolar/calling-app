<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$agent = requireAuth();
$pdo = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['full_name'] ?? '');
    $ext = in_array($_POST['sip_extension'] ?? $agent['sip_extension'], ['1001', '1002', '1003'], true)
        ? $_POST['sip_extension']
        : $agent['sip_extension'];
    $sound = isset($_POST['sound_enabled']) ? (int)((bool)$_POST['sound_enabled']) : (int)$agent['sound_enabled'];
    $alarm = isset($_POST['alarm_enabled']) ? (int)((bool)$_POST['alarm_enabled']) : (int)$agent['alarm_enabled'];
    $avatarUrl = $agent['avatar_url'];

    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK || $_FILES['avatar']['size'] > 2 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Profile image must be smaller than 2 MB.']);
            exit;
        }
        if (!class_exists('finfo')) {
            throw new RuntimeException('The PHP fileinfo extension is required for profile uploads.');
        }
        if (!is_uploaded_file($_FILES['avatar']['tmp_name'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid profile image upload.']);
            exit;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['avatar']['tmp_name']);
        $imageTypes = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        if (!isset($imageTypes[$mime])) {
            http_response_code(415);
            echo json_encode(['ok' => false, 'error' => 'Use a PNG, JPEG, or WebP profile image.']);
            exit;
        }
        $uploadDir = dirname(__DIR__) . '/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $fileName = 'agent_' . $agent['id'] . '_' . bin2hex(random_bytes(12)) . '.' . $imageTypes[$mime];
        if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadDir . '/' . $fileName)) {
            throw new RuntimeException('Unable to store the profile image.');
        }
        $avatarUrl = 'uploads/' . $fileName;
    }

    $stmt = $pdo->prepare('UPDATE agents SET full_name = ?, sip_extension = ?, avatar_url = ?, sound_enabled = ?, alarm_enabled = ? WHERE id = ?');
    $stmt->execute([$name !== '' ? $name : $agent['full_name'], $ext, $avatarUrl, $sound, $alarm, $agent['id']]);
    echo json_encode(['ok' => true, 'avatar_url' => $avatarUrl, 'sound_enabled' => $sound, 'alarm_enabled' => $alarm]);
    exit;
}

echo json_encode(['ok' => true, 'agent' => $agent]);