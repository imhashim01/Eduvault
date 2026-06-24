<?php
/* =====================================================
   EduVault – php/login.php
   Handles student & admin authentication
   ===================================================== */

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

/* ---- Only accept POST ---- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed.');
}

/* ---- Collect & validate ---- */
$email    = clean($_POST['email']    ?? '');
$password = $_POST['password']       ?? '';
$role     = clean($_POST['role']     ?? 'student');
$remember = !empty($_POST['remember']);

if (empty($email) || empty($password)) {
    jsonResponse(false, 'Email and password are required.');
}
if (!validEmail($email)) {
    jsonResponse(false, 'Please enter a valid email address.');
}
if (!in_array($role, ['student', 'admin'], true)) {
    jsonResponse(false, 'Invalid role.');
}

/* ---- Rate limiting (simple: 5 attempts per IP per 15 min) ---- */
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (isset($_SESSION['login_attempts'][$ip])) {
    $attempts = $_SESSION['login_attempts'][$ip];
    if ($attempts['count'] >= 5 && (time() - $attempts['first']) < 900) {
        jsonResponse(false, 'Too many login attempts. Please wait 15 minutes.');
    }
    if ((time() - $attempts['first']) >= 900) {
        unset($_SESSION['login_attempts'][$ip]);
    }
}

/* ---- Look up user ---- */
try {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'SELECT * FROM users WHERE email = ? AND role = ? LIMIT 1'
    );
    $stmt->execute([$email, $role]);
    $user = $stmt->fetch();

    /* ---- Verify password ---- */
    if (!$user || !password_verify($password, $user['password_hash'])) {
        /* Track failed attempt */
        if (!isset($_SESSION['login_attempts'][$ip])) {
            $_SESSION['login_attempts'][$ip] = ['count' => 0, 'first' => time()];
        }
        $_SESSION['login_attempts'][$ip]['count']++;

        jsonResponse(false, 'Incorrect email or password.');
    }

    if ((int)$user['is_active'] !== 1) {
        jsonResponse(false, 'Your account has been suspended. Contact support.');
    }

    /* ---- Clear rate limit on success ---- */
    unset($_SESSION['login_attempts'][$ip]);

    /* ---- Build session ---- */
    session_regenerate_id(true);
    $_SESSION['user_id']   = (int)$user['id'];
    $_SESSION['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
    $_SESSION['email']     = $user['email'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['dept']      = $user['department'] ?? '';

    /* ---- Remember-me cookie (30 days) ---- */
    if ($remember) {
        $token   = bin2hex(random_bytes(32));
        $expires = time() + 60 * 60 * 24 * 30;

        $pdo->prepare(
            'UPDATE users SET remember_token = ?, token_expires = ? WHERE id = ?'
        )->execute([$token, date('Y-m-d H:i:s', $expires), $user['id']]);

        setcookie('eduvault_token', $token, [
            'expires'  => $expires,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
    }

    /* ---- Update last login ---- */
    $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
        ->execute([$user['id']]);

    /* ---- Log activity ---- */
    logActivity($pdo, $user['id'], 'login');

    /* ---- Respond ---- */
    jsonResponse(true, 'Login successful.', [
        'redirect' => '../dashboard.html',
        'user' => [
            'id'   => $user['id'],
            'name' => $_SESSION['full_name'],
            'role' => $user['role'],
        ]
    ]);

} catch (PDOException $e) {
    error_log('[EduVault Login] ' . $e->getMessage());
    jsonResponse(false, 'A server error occurred. Please try again later.');
}
