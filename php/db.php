<?php
/* =====================================================
   EduVault – php/db.php
   PDO connection singleton + shared helper functions
   =====================================================
   All credentials are read from config.php.
   Edit config.php — not this file.
   ===================================================== */

require_once __DIR__ . '/config.php';

/* ---- Get PDO connection (singleton) ---- */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        http_response_code(500);

        $hint = '';
        if (strpos($e->getMessage(), 'Access denied') !== false) {
            $hint = 'Hint: Check DB_USER and DB_PASS in php/config.php.';
        } elseif (strpos($e->getMessage(), 'Unknown database') !== false) {
            $hint = 'Hint: The database "' . DB_NAME . '" does not exist. '
                  . 'Import database/eduvault.sql in phpMyAdmin first.';
        } elseif (strpos($e->getMessage(), 'Connection refused') !== false
               || strpos($e->getMessage(), "Can't connect") !== false) {
            $hint = 'Hint: Make sure the MySQL service is running in the XAMPP Control Panel.';
        }

        die(json_encode([
            'success' => false,
            'message' => 'Database connection failed. ' . $hint,
            'detail'  => defined('DEBUG_MODE') && DEBUG_MODE ? $e->getMessage() : '',
        ]));
    }

    return $pdo;
}

/* ---- Test the connection and return a status array ---- */
function testConnection(): array {
    try {
        $pdo  = getDB();
        $ver  = $pdo->query('SELECT VERSION()')->fetchColumn();
        $db   = $pdo->query('SELECT DATABASE()')->fetchColumn();
        return [
            'ok'      => true,
            'host'    => DB_HOST,
            'port'    => DB_PORT,
            'db'      => $db,
            'version' => $ver,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/* ---- Send JSON response and exit ---- */
function jsonResponse(bool $success, string $message, array $data = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(
        ['success' => $success, 'message' => $message],
        $data
    ));
    exit;
}

/* ---- Sanitize a string value ---- */
function clean(string $value): string {
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

/* ---- Validate email ---- */
function validEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/* ---- Require logged-in session ---- */
function requireAuth(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            jsonResponse(false, 'Unauthorized. Please log in.');
        }
        header('Location: ../login.html');
        exit;
    }
}

/* ---- Require admin role ---- */
function requireAdmin(): void {
    requireAuth();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        jsonResponse(false, 'Forbidden. Admin access required.');
    }
}

/* ---- Log an activity ---- */
function logActivity(PDO $pdo, int $userId, string $action, ?int $refId = null): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO activity_log (user_id, action, reference_id, ip_address, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $userId,
            $action,
            $refId,
            $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (PDOException $e) {
        error_log('[EduVault Log] ' . $e->getMessage());
    }
}
