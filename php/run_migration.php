<?php
/* ============================================================
   EduVault – run_migration.php
   Run this ONCE to add AI detection columns to your database.
   Open in browser: http://localhost/EduVault2_XAMPP/php/run_migration.php
   ============================================================ */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$pdo = getDB();
$results = [];

/* 1. Add ai_probability column to submissions */
try {
    $pdo->exec("ALTER TABLE submissions ADD COLUMN ai_probability DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER similarity_score");
    $results[] = ['ok', 'Added ai_probability column to submissions table'];
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        $results[] = ['skip', 'ai_probability column already exists — skipped'];
    } else {
        $results[] = ['err', 'submissions alter: ' . $e->getMessage()];
    }
}

/* 2. Create ai_detection_results table */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ai_detection_results (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id   INT UNSIGNED NOT NULL,
            ai_probability  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            verdict         VARCHAR(30)  NOT NULL DEFAULT 'unknown',
            confidence      VARCHAR(10)  NOT NULL DEFAULT 'Low',
            detectors_json  LONGTEXT,
            flagged_json    TEXT,
            analysis_note   TEXT,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_sub (submission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results[] = ['ok', 'Created ai_detection_results table'];
} catch (PDOException $e) {
    $results[] = ['err', 'ai_detection_results create: ' . $e->getMessage()];
}

/* Output */
header('Content-Type: text/html');
echo '<!DOCTYPE html><html><head><title>EduVault Migration</title>';
echo '<style>body{font-family:Arial;background:#1a1a2e;color:#e0e0e0;padding:40px;}';
echo '.ok{color:#22c55e;} .skip{color:#eab308;} .err{color:#ef4444;}';
echo 'h1{color:#00c9b1;} .box{background:#0f0f20;border:1px solid #2a2a4a;border-radius:8px;padding:20px;max-width:600px;}';
echo '.btn{display:inline-block;padding:10px 22px;background:#00c9b1;color:#000;border-radius:5px;text-decoration:none;font-weight:bold;margin-top:20px;}';
echo '</style></head><body>';
echo '<div class="box">';
echo '<h1>&#9698; EduVault – Database Migration</h1>';
foreach ($results as $r) {
    $icon = $r[0] === 'ok' ? '✓' : ($r[0] === 'skip' ? '↷' : '✗');
    echo '<p class="' . $r[0] . '">' . $icon . ' ' . htmlspecialchars($r[1]) . '</p>';
}
echo '<p style="color:#9090aa;margin-top:16px;">Migration complete. You can delete this file after running it.</p>';
echo '<a href="../dashboard.html" class="btn">Go to Dashboard</a>';
echo '</div></body></html>';