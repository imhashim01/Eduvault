<?php
/* =====================================================
   EduVault – php/compare.php
   Returns full similarity breakdown for a submission
   ===================================================== */

ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed.');
}

$id     = (int)($_GET['id'] ?? 0);
$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'] ?? 'student';

if ($id <= 0) jsonResponse(false, 'Invalid submission ID.');

try {
    $pdo = getDB();

    /* ---- Fetch submission (students see only their own) ---- */
    if ($role === 'admin') {
        $stmt = $pdo->prepare('SELECT * FROM submissions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT * FROM submissions WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
    }

    $sub = $stmt->fetch();
    if (!$sub) jsonResponse(false, 'Submission not found or access denied.');

    /* ---- Fetch top matches ---- */
    $mstmt = $pdo->prepare(
        'SELECT sm.score,
                s.title            AS matched_title,
                s.original_filename,
                u.first_name, u.last_name,
                sm.matched_text_snippet
         FROM   similarity_matches sm
         JOIN   submissions s ON s.id = sm.matched_submission_id
         JOIN   users u       ON u.id = s.user_id
         WHERE  sm.submission_id = ?
         ORDER  BY sm.score DESC
         LIMIT  10'
    );
    $mstmt->execute([$id]);
    $matches = $mstmt->fetchAll();

    /* ---- Compute 5-axis breakdown ---- */
    $score     = (float)$sub['similarity_score'];
    $text      = (string)($sub['extracted_text'] ?? '');
    $breakdown = computeBreakdown($text, $score, $matches);

    jsonResponse(true, 'Analysis loaded.', [
        'submission' => [
            'id'               => $sub['id'],
            'title'            => $sub['title'],
            'course'           => $sub['course_code'],
            'filename'         => $sub['original_filename'],
            'similarity_score' => $score,
            'risk_level'       => $sub['risk_level'],
            'status'           => $sub['status'],
            'submitted_at'     => $sub['created_at'],
        ],
        'breakdown' => $breakdown,
        'matches'   => array_map(function ($m) {
            return [
                'source'  => $m['first_name'] . ' ' . $m['last_name']
                             . ' — ' . $m['matched_title'],
                'score'   => (float)$m['score'],
                'snippet' => $m['matched_text_snippet'] ?? '(No snippet available)',
            ];
        }, $matches),
    ]);

} catch (PDOException $e) {
    error_log('[EduVault Compare] ' . $e->getMessage());
    jsonResponse(false, 'Server error during analysis retrieval.');
}

/* ---- Heuristic 5-axis breakdown ---- */
function computeBreakdown($text, $overallScore, $matches) {
    /* Originality = inverse of similarity */
    $originality = max(0, 100 - $overallScore);

    /* Structural match = top match score */
    $structural  = !empty($matches) ? (float)$matches[0]['score'] : 0.0;

    /* Citation integrity: count reference patterns */
    preg_match_all('/\[\d+\]|\(\w+,?\s*\d{4}\)|doi:|et al\./i', $text, $cites);
    $citation = min(100, count($cites[0]) * 8);

    /* Paraphrase index: proportional to overall */
    $paraphrase = min(100, round($overallScore * 0.85));

    /* Source diversity: number of unique matched sources */
    $diversity = min(100, count($matches) * 12);

    return [
        'originality'      => round($originality,  1),
        'structural'       => round($structural,    1),
        'citation'         => round($citation,      1),
        'paraphrase'       => round($paraphrase,    1),
        'source_diversity' => round($diversity,     1),
    ];
}