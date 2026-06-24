<?php
/* =====================================================
   EduVault – php/dashboard_data.php
   Returns real DB data as JSON for dashboard.js
   ===================================================== */

ob_start(); // Buffer any stray output (warnings etc.) so JSON is not corrupted
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

requireAuth();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'] ?? 'student';
$action = $_GET['action'] ?? 'submissions';

try {
    /* ---- My submissions (student view) ---- */
    if ($action === 'submissions') {
        $stmt = $pdo->prepare(
            'SELECT id, title, course_code AS course,
                    DATE_FORMAT(created_at,"%Y-%m-%d") AS date,
                    similarity_score AS sim, ai_probability AS ai_prob, risk_level AS risk, status
             FROM submissions
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 50'
        );
        $stmt->execute([$userId]);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }

    /* ---- Admin: all submissions ---- */
    if ($action === 'admin_submissions' && $role === 'admin') {
        $stmt = $pdo->query(
            'SELECT s.id,
                    CONCAT(u.first_name," ",u.last_name) AS student,
                    s.title AS assign,
                    u.department AS dept,
                    DATE_FORMAT(s.created_at,"%Y-%m-%d") AS date,
                    s.similarity_score AS sim,
                    s.ai_probability AS ai_prob,
                    s.risk_level AS risk,
                    s.status
             FROM submissions s
             JOIN users u ON u.id = s.user_id
             ORDER BY s.created_at DESC
             LIMIT 100'
        );
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }

    /* ---- Overview stats ---- */
    if ($action === 'stats') {
        if ($role === 'admin') {
            $total   = $pdo->query('SELECT COUNT(*) FROM submissions')->fetchColumn();
            $flagged = $pdo->query("SELECT COUNT(*) FROM submissions WHERE risk_level='high'")->fetchColumn();
            $users   = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $avg     = $pdo->query('SELECT ROUND(AVG(similarity_score),1) FROM submissions')->fetchColumn();
        } else {
            $total   = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE user_id=?');
            $total->execute([$userId]); $total = $total->fetchColumn();
            $flagged = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE user_id=? AND risk_level='high'");
            $flagged->execute([$userId]); $flagged = $flagged->fetchColumn();
            $users   = 1;
            $avgQ    = $pdo->prepare('SELECT ROUND(AVG(similarity_score),1) FROM submissions WHERE user_id=?');
            $avgQ->execute([$userId]); $avg = $avgQ->fetchColumn() ?? 0;
        }
        echo json_encode([
            'success' => true,
            'total'   => (int)$total,
            'flagged' => (int)$flagged,
            'users'   => (int)$users,
            'avg_sim' => (float)$avg,
        ]);
        exit;
    }

    /* ---- Session info ---- */
    if ($action === 'me') {
        echo json_encode([
            'success'   => true,
            'name'      => $_SESSION['full_name'] ?? 'User',
            'role'      => $role,
            'email'     => $_SESSION['email'] ?? '',
            'dept'      => $_SESSION['dept']  ?? '',
        ]);
        exit;
    }

    jsonResponse(false, 'Unknown action.');

} catch (PDOException $e) {
    error_log('[EduVault Dashboard] ' . $e->getMessage());
    jsonResponse(false, 'Database error.');
}