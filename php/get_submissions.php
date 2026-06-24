<?php
/* ============================================================
   EduVault – php/get_submissions.php
   Returns the logged-in student's own submissions from the DB.
   Used by the Similarity Radar dropdown so it shows THEIR
   assignments, not hardcoded fake data.
   ============================================================ */

ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

requireAuth();

$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'] ?? 'student';

/* Optional filters from query string */
$search = clean($_GET['search'] ?? '');
$status = clean($_GET['status'] ?? '');
$risk   = clean($_GET['risk']   ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = (int)($_GET['limit']  ?? 50);
$offset = ($page - 1) * $limit;

try {
    $pdo = getDB();

    /* Build WHERE clause */
    $where  = [];
    $params = [];

    /* Students only see their own; admins see all */
    if ($role !== 'admin') {
        $where[]  = 's.user_id = ?';
        $params[] = $userId;
    }

    if ($search !== '') {
        $where[]  = '(s.title LIKE ? OR s.course_code LIKE ?)';
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if ($status !== '') {
        $where[]  = 's.status = ?';
        $params[] = $status;
    }
    if ($risk !== '') {
        $where[]  = 's.risk_level = ?';
        $params[] = $risk;
    }

    $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    /* Count total */
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM submissions s $whereSQL"
    );
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    /* Fetch submissions */
    $stmt = $pdo->prepare(
        "SELECT
            s.id,
            s.title,
            s.course_code,
            s.assignment_type,
            s.original_filename,
            s.file_size,
            s.similarity_score,
            s.ai_probability,
            s.risk_level,
            s.status,
            s.created_at,
            u.first_name,
            u.last_name,
            u.student_id,
            u.department
         FROM submissions s
         JOIN users u ON u.id = s.user_id
         $whereSQL
         ORDER BY s.created_at DESC
         LIMIT $limit OFFSET $offset"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    /* Format for frontend */
    $submissions = array_map(function($r) {
        return [
            'id'               => (int)$r['id'],
            'title'            => $r['title'],
            'course_code'      => $r['course_code'],
            'assignment_type'  => $r['assignment_type'],
            'original_filename'=> $r['original_filename'],
            'file_size'        => (int)$r['file_size'],
            'similarity_score' => round((float)$r['similarity_score'], 2),
            'ai_probability'   => round((float)($r['ai_probability'] ?? 0), 2),
            'risk_level'       => $r['risk_level'],
            'status'           => $r['status'],
            'created_at'       => $r['created_at'],
            /* For admin table */
            'student_name'     => $r['first_name'] . ' ' . $r['last_name'],
            'student_id'       => $r['student_id'],
            'department'       => $r['department'],
        ];
    }, $rows);

    jsonResponse(true, 'Submissions loaded.', [
        'submissions' => $submissions,
        'total'       => $total,
        'page'        => $page,
        'pages'       => (int)ceil($total / $limit),
    ]);

} catch (PDOException $e) {
    error_log('[EduVault GetSubmissions] ' . $e->getMessage());
    jsonResponse(false, 'Database error: ' . $e->getMessage());
}