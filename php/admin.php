<?php
/* =====================================================
   EduVault – php/admin.php
   Admin CRUD: students, submissions, stats, export
   ===================================================== */

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

requireAdmin();

$action = clean($_REQUEST['action'] ?? '');

try {
    $pdo = getDB();

    switch ($action) {

        /* ==================== READ ==================== */

        case 'list_students':
            $search = clean($_GET['search'] ?? '');
            $dept   = clean($_GET['dept']   ?? '');
            $page   = max(1, (int)($_GET['page'] ?? 1));
            $limit  = 20;
            $offset = ($page - 1) * $limit;

            $where  = ['role = "student"'];
            $params = [];

            if ($search) {
                $like     = '%' . $search . '%';
                $where[]  = '(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR student_id LIKE ?)';
                $params   = array_merge($params, [$like, $like, $like, $like]);
            }
            if ($dept) {
                $where[]  = 'department = ?';
                $params[] = $dept;
            }

            $w = 'WHERE ' . implode(' AND ', $where);

            $total = $pdo->prepare("SELECT COUNT(*) FROM users $w");
            $total->execute($params);
            $totalCount = (int)$total->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT id, first_name, last_name, email, student_id,
                        institution, department, is_active, created_at, last_login
                 FROM   users $w
                 ORDER  BY created_at DESC
                 LIMIT  $limit OFFSET $offset"
            );
            $stmt->execute($params);

            jsonResponse(true, 'OK', [
                'students' => $stmt->fetchAll(),
                'total'    => $totalCount,
                'page'     => $page,
                'pages'    => (int)ceil($totalCount / $limit),
            ]);
            break;

        case 'list_submissions':
            $search = clean($_GET['search'] ?? '');
            $risk   = clean($_GET['risk']   ?? '');
            $dept   = clean($_GET['dept']   ?? '');
            $page   = max(1, (int)($_GET['page'] ?? 1));
            $limit  = 25;
            $offset = ($page - 1) * $limit;

            $where  = ['1=1'];
            $params = [];

            if ($search) {
                $like    = '%' . $search . '%';
                $where[] = '(s.title LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
                $params  = array_merge($params, [$like, $like, $like]);
            }
            if ($risk) {
                $where[]  = 's.risk_level = ?';
                $params[] = $risk;
            }
            if ($dept) {
                $where[]  = 'u.department = ?';
                $params[] = $dept;
            }

            $w = implode(' AND ', $where);

            $total = $pdo->prepare(
                "SELECT COUNT(*) FROM submissions s JOIN users u ON u.id=s.user_id WHERE $w"
            );
            $total->execute($params);
            $totalCount = (int)$total->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT s.id, s.title, s.course_code, s.assignment_type,
                        s.similarity_score, s.risk_level, s.status,
                        s.original_filename, s.created_at,
                        u.first_name, u.last_name, u.student_id, u.department
                 FROM   submissions s
                 JOIN   users u ON u.id = s.user_id
                 WHERE  $w
                 ORDER  BY s.created_at DESC
                 LIMIT  $limit OFFSET $offset"
            );
            $stmt->execute($params);

            jsonResponse(true, 'OK', [
                'submissions' => $stmt->fetchAll(),
                'total'       => $totalCount,
                'page'        => $page,
                'pages'       => (int)ceil($totalCount / $limit),
            ]);
            break;

        case 'dashboard_stats':
            jsonResponse(true, 'OK', [
                'stats' => [
                    'total_students'   => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role="student"')->fetchColumn(),
                    'total_submissions'=> (int)$pdo->query('SELECT COUNT(*) FROM submissions')->fetchColumn(),
                    'flagged'          => (int)$pdo->query('SELECT COUNT(*) FROM submissions WHERE risk_level="high"')->fetchColumn(),
                    'avg_similarity'   => round((float)$pdo->query('SELECT AVG(similarity_score) FROM submissions')->fetchColumn(), 1),
                    'monthly'          => $pdo->query(
                        "SELECT DATE_FORMAT(created_at,'%Y-%m') AS month, COUNT(*) AS count
                         FROM submissions
                         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                         GROUP BY month ORDER BY month"
                    )->fetchAll(),
                ]
            ]);
            break;

        /* ==================== UPDATE ==================== */

        case 'update_submission_status':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'POST required.');
            $id     = (int)($_POST['id'] ?? 0);
            $status = clean($_POST['status'] ?? '');
            if (!in_array($status, ['approved','flagged','rejected'], true))
                jsonResponse(false, 'Invalid status.');

            $pdo->prepare('UPDATE submissions SET status=? WHERE id=?')
                ->execute([$status, $id]);

            logActivity($pdo, $_SESSION['user_id'], 'update_status', $id);
            jsonResponse(true, 'Status updated.');
            break;

        case 'update_student':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'POST required.');
            $id        = (int)($_POST['id'] ?? 0);
            $firstName = clean($_POST['first_name']  ?? '');
            $lastName  = clean($_POST['last_name']   ?? '');
            $dept      = clean($_POST['department']  ?? '');
            $isActive  = (int)(bool)($_POST['is_active'] ?? 1);

            $pdo->prepare(
                'UPDATE users SET first_name=?, last_name=?, department=?, is_active=?
                 WHERE id=? AND role="student"'
            )->execute([$firstName, $lastName, $dept, $isActive, $id]);

            logActivity($pdo, $_SESSION['user_id'], 'update_student', $id);
            jsonResponse(true, 'Student updated.');
            break;

        /* ==================== DELETE ==================== */

        case 'delete_submission':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'POST required.');
            $id = (int)($_POST['id'] ?? 0);

            $row = $pdo->prepare('SELECT stored_filename FROM submissions WHERE id=?');
            $row->execute([$id]);
            $file = $row->fetch();
            if ($file && $file['stored_filename']) {
                @unlink(__DIR__ . '/../uploads/' . $file['stored_filename']);
            }

            $pdo->prepare('DELETE FROM similarity_matches WHERE submission_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM submissions WHERE id=?')->execute([$id]);

            logActivity($pdo, $_SESSION['user_id'], 'delete_submission', $id);
            jsonResponse(true, 'Submission deleted.');
            break;

        case 'delete_student':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'POST required.');
            $id = (int)($_POST['id'] ?? 0);

            /* Soft delete */
            $pdo->prepare('UPDATE users SET is_active=0 WHERE id=? AND role="student"')
                ->execute([$id]);

            logActivity($pdo, $_SESSION['user_id'], 'deactivate_student', $id);
            jsonResponse(true, 'Student deactivated.');
            break;

        /* ==================== EXPORT ==================== */

        case 'export_csv':
            /* Switch output to CSV */
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="eduvault_export_' . date('Ymd') . '.csv"');

            $rows = $pdo->query(
                "SELECT u.first_name, u.last_name, u.email, u.student_id, u.department,
                        s.title, s.course_code, s.assignment_type,
                        s.similarity_score, s.risk_level, s.status, s.created_at
                 FROM   submissions s
                 JOIN   users u ON u.id = s.user_id
                 ORDER  BY s.created_at DESC"
            );

            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'First Name','Last Name','Email','Student ID','Department',
                'Assignment','Course','Type','Similarity %','Risk','Status','Date'
            ]);
            while ($row = $rows->fetch()) {
                fputcsv($out, array_values($row));
            }
            fclose($out);
            exit;

        default:
            jsonResponse(false, 'Unknown action: ' . htmlspecialchars($action));
    }

} catch (PDOException $e) {
    error_log('[EduVault Admin] ' . $e->getMessage());
    jsonResponse(false, 'Server error: ' . $e->getMessage());
}
