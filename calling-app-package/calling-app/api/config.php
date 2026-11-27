<?php
declare(strict_types=1);

function loadEnv(string $path): void {
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        $parts = explode('=', $trimmed, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1], " \t\n\r\0\x0B\"'");
            $_ENV[$key] = $val;
            putenv("$key=$val");
        }
    }
}

loadEnv(dirname(__DIR__) . '/.env');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $error): void {
    error_log((string)$error);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => 'The server could not complete the request.']);
});

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off';
session_name('agent_portal');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
session_start();

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = array_filter(array_map('trim', explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? '')));
if ($origin !== '') {
    $scheme = $isHttps ? 'https' : 'http';
    $sameOrigin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    if ($origin !== $sameOrigin && !in_array($origin, $allowed, true)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Request origin is not allowed.']);
        exit;
    }
}
if ($origin !== '' && in_array($origin, $allowed, true) && $origin !== ($isHttps ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '')) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
}
header("Vary: Origin");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function getDb(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $required = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];
    foreach ($required as $key) {
        if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
            throw new RuntimeException("Missing required database setting: {$key}");
        }
    }
    $host = $_ENV['DB_HOST'];
    $port = $_ENV['DB_PORT'];
    $db = $_ENV['DB_NAME'];
    $user = $_ENV['DB_USER'];
    $pass = $_ENV['DB_PASSWORD'];

    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function requireAuth(): array {
    if (empty($_SESSION['agent_id'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized session']);
        exit;
    }
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT id, full_name, email, sip_extension, role, email_verified, avatar_url, sound_enabled, alarm_enabled FROM agents WHERE id = ?');
    $stmt->execute([$_SESSION['agent_id']]);
    $agent = $stmt->fetch();
    if (!$agent) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Account not found']);
        exit;
    }
    return $agent;
}

function sendPasswordNotice(string $toEmail, string $name): bool {
    $subject = 'Tata Power Solarooof - Password Updated';
    $body = "Hello {$name},\n\nYour password for the Tata Power Solarooof Agent Console was updated.\nIf you did not initiate this change, contact support immediately.\n";
    $headers = "From: noreply@tatapowersolar.shop\r\nReply-To: mail.tatapowersolar.shop\r\nContent-Type: text/plain; charset=UTF-8";
    return mail($toEmail, $subject, $body, $headers);
}