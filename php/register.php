<?php
/* =====================================================
   EduVault – php/register.php
   ===================================================== */

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed.');
}

/* ---- Collect input ---- */
$firstName   = clean($_POST['first_name']      ?? '');
$lastName    = clean($_POST['last_name']       ?? '');
$email       = clean($_POST['email']           ?? '');
$institution = clean($_POST['institution']     ?? '');
$studentId   = clean($_POST['student_id']      ?? '');
$department  = clean($_POST['department']      ?? '');
$password    = $_POST['password']              ?? '';
$confirmPw   = $_POST['confirm_password']      ?? '';

/* ---- Validate ---- */
$errors = [];
if (strlen($firstName) < 2)  $errors[] = 'First name must be at least 2 characters.';
if (strlen($lastName)  < 2)  $errors[] = 'Last name must be at least 2 characters.';
if (!validEmail($email))     $errors[] = 'Please enter a valid email address.';
if (empty($institution))     $errors[] = 'Institution name is required.';
if (strlen($password) < 8)   $errors[] = 'Password must be at least 8 characters.';
if ($password !== $confirmPw) $errors[] = 'Passwords do not match.';

if (!empty($errors)) {
    jsonResponse(false, implode(' ', $errors));
}

try {
    $pdo = getDB();

    /* ---- Check email uniqueness ---- */
    $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $chk->execute([$email]);
    if ($chk->fetch()) {
        jsonResponse(false, 'An account with this email already exists. Please login instead.');
    }

    /* ---- Check student_id uniqueness (only if provided) ---- */
    if (!empty($studentId)) {
        $chk2 = $pdo->prepare('SELECT id FROM users WHERE student_id = ? LIMIT 1');
        $chk2->execute([$studentId]);
        if ($chk2->fetch()) {
            jsonResponse(false, 'This Student ID is already registered.');
        }
    }

    /* ---- Insert user ---- */
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

    /* Use NULL for empty student_id to avoid unique key conflicts */
    $sid = !empty($studentId) ? $studentId : null;

    $stmt = $pdo->prepare(
        'INSERT INTO users
           (first_name, last_name, email, password_hash,
            institution, student_id, department, role, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, "student", 1, NOW())'
    );
    $stmt->execute([
        $firstName, $lastName, $email, $hash,
        $institution, $sid, $department
    ]);

    $newId = (int)$pdo->lastInsertId();

    /* ---- Auto-login ---- */
    session_regenerate_id(true);
    $_SESSION['user_id']   = $newId;
    $_SESSION['full_name'] = $firstName . ' ' . $lastName;
    $_SESSION['email']     = $email;
    $_SESSION['role']      = 'student';
    $_SESSION['dept']      = $department;

    logActivity($pdo, $newId, 'register');

    jsonResponse(true, 'Account created! Welcome to EduVault.');

} catch (PDOException $e) {
    $msg = $e->getMessage();
    error_log('[EduVault Register] ' . $msg);

    /* Give a specific message for common DB errors */
    if (strpos($msg, 'Duplicate entry') !== false && strpos($msg, 'uq_email') !== false) {
        jsonResponse(false, 'This email is already registered.');
    }
    if (strpos($msg, 'Duplicate entry') !== false && strpos($msg, 'uq_student_id') !== false) {
        jsonResponse(false, 'This Student ID is already taken. Leave it blank if you don\'t have one.');
    }

    /* Show full error in debug mode */
    $detail = defined('DEBUG_MODE') && DEBUG_MODE ? ' Error: ' . $msg : '';
    jsonResponse(false, 'Registration failed.' . $detail);
}
