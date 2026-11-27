<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$pdo = getDb();
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($input['action'] ?? '');

if ($action === 'signup') {
    $name = trim($input['full_name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $ext = in_array($input['sip_extension'] ?? '1001', ['1001', '1002', '1003'], true) ? $input['sip_extension'] : '1001';

    if ($name === '' || strlen($name) > 120 || strlen($email) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || strlen($password) > 72) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Provide a valid name, email, and password between 12 and 72 bytes.']);
        exit;
    }

    $code = (string)random_int(100000, 999999);
    $hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare('INSERT INTO agents (full_name, email, sip_extension, role, password_hash, email_verified, verification_code) VALUES (?, ?, ?, "staff", ?, 0, ?)');
        $stmt->execute([$name, $email, $ext, $hash, $code]);
        $id = (int)$pdo->lastInsertId();
        session_regenerate_id(true);
        $_SESSION['agent_id'] = $id;
        $sent = mail($email, 'Tata Power Solarooof - Verify Your Email', "Your verification code is: {$code}", "From: noreply@tatapowersolar.shop");
        echo json_encode([
            'ok' => true,
            'message' => $sent ? 'Account created. Verify your email.' : 'Account created, but the verification email could not be sent. Contact support.',
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') {
            throw $e;
        }
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Email already registered.']);
    }
    exit;
}

if ($action === 'login') {
    $email = strtolower(trim($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');

    $stmt = $pdo->prepare('SELECT id, full_name, email, sip_extension, role, password_hash, email_verified FROM agents WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid email or password.']);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['agent_id'] = (int)$user['id'];
    echo json_encode([
        'ok' => true,
        'agent' => [
            'id' => $user['id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'sip_extension' => $user['sip_extension'],
            'role' => $user['role'],
            'email_verified' => (int)$user['email_verified'],
        ]
    ]);
    exit;
}

if ($action === 'verify_email') {
    $agent = requireAuth();
    $code = trim($input['code'] ?? '');
    $stmt = $pdo->prepare('SELECT verification_code FROM agents WHERE id = ?');
    $stmt->execute([$agent['id']]);
    $row = $stmt->fetch();

    if ($code !== '' && $row && $row['verification_code'] === $code) {
        $upd = $pdo->prepare('UPDATE agents SET email_verified = 1, verification_code = NULL WHERE id = ?');
        $upd->execute([$agent['id']]);
        echo json_encode(['ok' => true, 'message' => 'Email address verified.']);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid verification code.']);
    }
    exit;
}

if ($action === 'send_verification') {
    $agent = requireAuth();
    $code = (string)random_int(100000, 999999);
    $stmt = $pdo->prepare('UPDATE agents SET verification_code = ? WHERE id = ?');
    $stmt->execute([$code, $agent['id']]);
    $sent = mail($agent['email'], 'Tata Power Solarooof - Verification Code', "Your code is: {$code}", "From: noreply@tatapowersolar.shop");
    echo json_encode([
        'ok' => $sent,
        'message' => $sent ? 'Verification code sent.' : 'The verification email could not be sent. Contact support.',
    ]);
    exit;
}

if ($action === 'change_password') {
    $agent = requireAuth();
    $current = (string)($input['current_password'] ?? '');
    $newPass = (string)($input['new_password'] ?? '');

    if (strlen($newPass) < 12) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'New password must be at least 12 characters.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT password_hash, email, full_name, email_verified FROM agents WHERE id = ?');
    $stmt->execute([$agent['id']]);
    $row = $stmt->fetch();

    $allowedCurrent = $row && password_verify($current, $row['password_hash']);

    if (!$allowedCurrent) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']);
        exit;
    }

    if (strlen($newPass) > 72) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'New password must be no more than 72 bytes.']);
        exit;
    }

    $newHash = password_hash($newPass, PASSWORD_DEFAULT);
    $upd = $pdo->prepare('UPDATE agents SET password_hash = ? WHERE id = ?');
    $upd->execute([$newHash, $agent['id']]);

    $noticeSent = (int)$row['email_verified'] !== 1 || sendPasswordNotice($row['email'], $row['full_name']);
    echo json_encode([
        'ok' => true,
        'message' => $noticeSent ? 'Password updated.' : 'Password updated, but the notification email could not be sent.',
    ]);
    exit;
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, [
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Strict',
        ]);
    }
    session_destroy();
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'me') {
    $agent = requireAuth();
    echo json_encode(['ok' => true, 'agent' => $agent]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown auth action']);