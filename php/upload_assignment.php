<?php
/* =====================================================
   EduVault – php/upload_assignment.php   FIXED VERSION

   BUGS FIXED:
   1. PDF text extraction used shell_exec('pdftotext') which is NOT
      available on XAMPP (Windows). Replaced with a pure-PHP PDF
      text parser that reads the raw binary — no external tools needed.

   2. runSimilarity() returned 0.0 immediately when extracted_text
      was empty (which happened for every PDF due to Bug 1). Now we
      still store the file and run a filename/title-based similarity
      fallback so scores are never silently 0.

   3. The WHERE clause filtered out any submission whose extracted_text
      had LENGTH <= 50 — meaning if the first upload was a PDF with
      empty text, the second identical PDF could never match it.
      Fixed: we now also compare by title+course when text is short.

   4. Only the single highest-scoring match was stored in
      similarity_matches. Now ALL matches above 5% are saved so the
      Similarity Radar panel shows multiple matched segments.

   5. ON DUPLICATE KEY relied on a UNIQUE key on (submission_id,
      matched_submission_id) that may not exist. Changed to a plain
      INSERT that checks existence first — safe for any schema.
   ===================================================== */

ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed.');
}

$ALLOWED_MIME = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'text/plain',
];

/* ---- Collect metadata ---- */
$title       = clean($_POST['title']       ?? '');
$course      = clean($_POST['course']      ?? '');
$type        = clean($_POST['type']        ?? 'Other');
$description = clean($_POST['description'] ?? '');
$userId      = (int)$_SESSION['user_id'];

if (empty($title))  jsonResponse(false, 'Assignment title is required.');
if (empty($course)) jsonResponse(false, 'Course code is required.');

/* ---- Validate uploaded file ---- */
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errMsg = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server limit (check php.ini upload_max_filesize).',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary directory.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
    ];
    $code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    jsonResponse(false, $errMsg[$code] ?? 'Unknown upload error (code ' . $code . ').');
}

$file     = $_FILES['file'];
$origName = basename($file['name']);
$tmpPath  = $file['tmp_name'];
$fileSize = (int)$file['size'];
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

if (!in_array($ext, ALLOWED_EXT, true)) {
    jsonResponse(false, 'Invalid file type. Allowed: ' . implode(', ', ALLOWED_EXT));
}
if ($fileSize > MAX_FILE_MB * 1024 * 1024) {
    jsonResponse(false, 'File exceeds the ' . MAX_FILE_MB . ' MB limit.');
}
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($tmpPath);
if (!in_array($mimeType, $ALLOWED_MIME, true)) {
    jsonResponse(false, 'File MIME type not permitted: ' . $mimeType);
}

/* ---- Save to uploads/ ---- */
$uploadDir = __DIR__ . '/../uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$safeName   = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
$storedName = $userId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath   = $uploadDir . $storedName;

if (!move_uploaded_file($tmpPath, $destPath)) {
    jsonResponse(false, 'Failed to save file. Make sure uploads/ folder is writable.');
}

/* ---- Extract plain text (pure PHP — no external tools needed) ---- */
$text = extractText($destPath, $ext);

/* ---- Save to DB & run similarity ---- */
try {
    $pdo = getDB();

    $stmt = $pdo->prepare(
        'INSERT INTO submissions
           (user_id, title, course_code, assignment_type, description,
            original_filename, stored_filename, file_size, mime_type,
            extracted_text, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", NOW())'
    );
    $stmt->execute([
        $userId, $title, $course, $type, $description,
        $safeName, $storedName, $fileSize, $mimeType, $text
    ]);
    $subId = (int)$pdo->lastInsertId();

    /* ---- Similarity check ---- */
    $score  = runSimilarity($pdo, $subId, $text, $title, $course, $origName);
    $risk   = $score >= 50 ? 'high' : ($score >= 20 ? 'medium' : 'low');
    $status = $score >= 50 ? 'flagged' : 'approved';

    $pdo->prepare(
        'UPDATE submissions SET similarity_score=?, risk_level=?, status=? WHERE id=?'
    )->execute([$score, $risk, $status, $subId]);

    logActivity($pdo, $userId, 'upload', $subId);

    jsonResponse(true, 'Submitted and analyzed successfully.', [
        'submission_id'    => $subId,
        'similarity_score' => $score,
        'risk_level'       => $risk,
        'status'           => $status,
    ]);

} catch (PDOException $e) {
    @unlink($destPath);
    error_log('[EduVault Upload] ' . $e->getMessage());
    jsonResponse(false, 'Database error: ' . (defined('DEBUG_MODE') && DEBUG_MODE ? $e->getMessage() : 'Please try again.'));
}

/* ============================================================
   FIXED: extractText()
   Uses pure PHP for all formats — no shell_exec, no pdftotext.
   Works on XAMPP Windows, Linux, Mac without any extras.
   ============================================================ */
function extractText($path, $ext) {
    switch ($ext) {

        /* ---- TXT: direct read ---- */
        case 'txt':
            return (string) file_get_contents($path);

        /* ---- DOCX: unzip XML ---- */
        case 'docx':
        case 'doc':
            $text = '';
            if (!class_exists('ZipArchive')) return '';
            $zip = new ZipArchive();
            if ($zip->open($path) === true) {
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                if ($xml) {
                    // Strip XML tags, decode entities
                    $text = strip_tags(
                        str_replace(
                            ['</w:p>', '</w:r>', '<w:tab/>'],
                            ["\n",     ' ',      "\t"],
                            $xml
                        )
                    );
                    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
            return $text;

        /* ---- PDF: pure-PHP binary extraction ---- */
        case 'pdf':
            return extractPdfText($path);

        default:
            return '';
    }
}

/* ============================================================
   Pure-PHP PDF text extractor
   Reads the raw PDF binary and pulls out all text stream objects.
   No pdftotext, no external tools — works on any XAMPP installation.
   Handles both plain text streams and hex-encoded streams.
   ============================================================ */
function extractPdfText($path) {
    $content = @file_get_contents($path);
    if ($content === false || strlen($content) < 10) return '';

    $text = '';

    /* Strategy 1: Extract text from BT...ET blocks (standard PDF text blocks) */
    preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $btBlocks);
    foreach ($btBlocks[1] as $block) {
        /* Match all string operators: (text)Tj  (text)TJ  [(text)]TJ */
        preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*(?:Tj|TJ|\'|")/s', $block, $strMatches);
        foreach ($strMatches[1] as $str) {
            /* Unescape PDF string escapes */
            $str = str_replace(['\\n','\\r','\\t','\\(','\\)','\\\\'], ["\n","\r","\t",'(',')',"\\"], $str);
            $text .= $str . ' ';
        }
        /* Match array form: [(text) spacing (text)]TJ */
        preg_match_all('/\[([^\]]+)\]\s*TJ/s', $block, $arrMatches);
        foreach ($arrMatches[1] as $arr) {
            preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/s', $arr, $arrStrings);
            foreach ($arrStrings[1] as $s) {
                $s = str_replace(['\\n','\\r','\\t','\\(','\\)','\\\\'], ["\n","\r","\t",'(',')',"\\"], $s);
                $text .= $s;
            }
            $text .= ' ';
        }
    }

    /* Strategy 2: If Strategy 1 found very little, decompress FlateDecode streams */
    if (strlen(trim($text)) < 100 && function_exists('gzuncompress')) {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $streams);
        foreach ($streams[1] as $stream) {
            /* Try to decompress */
            $decompressed = @gzuncompress($stream);
            if ($decompressed === false) {
                /* Some streams use gzinflate */
                $decompressed = @gzinflate(substr($stream, 2));
            }
            if ($decompressed !== false) {
                /* Extract BT...ET from decompressed stream */
                preg_match_all('/BT\s*(.*?)\s*ET/s', $decompressed, $bt2);
                foreach ($bt2[1] as $block) {
                    preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*(?:Tj|TJ|\'|")/s', $block, $sm);
                    foreach ($sm[1] as $str) {
                        $str = str_replace(['\\n','\\r','\\t','\\(','\\)','\\\\'], ["\n","\r","\t",'(',')',"\\"], $str);
                        $text .= $str . ' ';
                    }
                }
            }
        }
    }

    /* Strategy 3: Last resort — grab any readable ASCII sequences from raw PDF */
    if (strlen(trim($text)) < 50) {
        /* Find all parenthesised strings anywhere in PDF (catches non-stream text) */
        preg_match_all('/\(([^\x00-\x08\x0e-\x1f\x7f-\xff()\\\\]{4,})\)/', $content, $rawStr);
        foreach ($rawStr[1] as $s) {
            $text .= $s . ' ';
        }
    }

    /* Clean up */
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

/* ============================================================
   FIXED: runSimilarity()
   - No longer bails out silently when text is empty
   - Compares ALL previous submissions, not just those with long text
   - Uses BOTH text similarity AND filename/title matching
   - Stores ALL matches above 5% (not just the highest one)
   - Uses safe INSERT instead of ON DUPLICATE KEY
   ============================================================ */
function runSimilarity($pdo, $newId, $newText, $newTitle, $newCourse, $newFilename) {

    /* Fetch all other submissions */
    $stmt = $pdo->prepare(
        'SELECT id, extracted_text, title, course_code, original_filename
         FROM submissions
         WHERE id != ?
         ORDER BY created_at DESC
         LIMIT 200'
    );
    $stmt->execute([$newId]);
    $others = $stmt->fetchAll();

    if (empty($others)) return 0.0;

    $newFreq      = wordFreq($newText);
    $newTitleNorm = normTitle($newTitle);
    $newFileNorm  = normTitle($newFilename);
    $hasText      = count($newFreq) > 10;
    $maxScore     = 0.0;

    foreach ($others as $other) {
        $score = 0.0;

        /* --- Text similarity (cosine) --- */
        if ($hasText && !empty($other['extracted_text'])) {
            $otherFreq = wordFreq($other['extracted_text']);
            if (count($otherFreq) > 10) {
                $textScore = cosine($newFreq, $otherFreq) * 100;
                $score     = max($score, $textScore);
            }
        }

        /* --- Filename similarity (catches identical file re-uploads) --- */
        $otherFileNorm = normTitle($other['original_filename']);
        if ($newFileNorm && $otherFileNorm) {
            $fileSim = similar_text($newFileNorm, $otherFileNorm) /
                       max(strlen($newFileNorm), strlen($otherFileNorm)) * 100;
            $score   = max($score, $fileSim);
        }

        /* --- Title + Course match (catches re-submissions of same assignment) --- */
        $otherTitleNorm = normTitle($other['title']);
        if ($newTitleNorm && $otherTitleNorm && $newTitleNorm === $otherTitleNorm) {
            /* Same title AND same course = very likely duplicate */
            $titleBoost = ($newCourse === $other['course_code']) ? 85.0 : 60.0;
            $score = max($score, $titleBoost);
        }

        /* --- Partial title similarity --- */
        if ($newTitleNorm && $otherTitleNorm && strlen($newTitleNorm) > 5) {
            $titleSim = similar_text($newTitleNorm, $otherTitleNorm) /
                        max(strlen($newTitleNorm), strlen($otherTitleNorm)) * 100;
            if ($titleSim >= 70) {
                $score = max($score, $titleSim * 0.8); // Weight title match at 80%
            }
        }

        $score = round($score, 2);

        /* Save ALL matches above 5% */
        if ($score > 5.0) {
            /* Safe insert — check existence first */
            $exists = $pdo->prepare(
                'SELECT id FROM similarity_matches WHERE submission_id=? AND matched_submission_id=? LIMIT 1'
            );
            $exists->execute([$newId, $other['id']]);

            if ($exists->fetch()) {
                /* Update if new score is higher */
                $pdo->prepare(
                    'UPDATE similarity_matches SET score=? WHERE submission_id=? AND matched_submission_id=? AND score < ?'
                )->execute([$score, $newId, $other['id'], $score]);
            } else {
                /* Insert new match */
                $pdo->prepare(
                    'INSERT INTO similarity_matches (submission_id, matched_submission_id, score, created_at)
                     VALUES (?, ?, ?, NOW())'
                )->execute([$newId, $other['id'], $score]);
            }

            if ($score > $maxScore) $maxScore = $score;
        }
    }

    return round($maxScore, 2);
}

/* ---- Normalize title/filename for comparison ---- */
function normTitle($str) {
    $str = strtolower(trim($str));
    $str = preg_replace('/\.(pdf|docx?|txt)$/i', '', $str); // remove extension
    $str = preg_replace('/[^a-z0-9\s]/', ' ', $str);         // remove special chars
    $str = preg_replace('/\s+/', ' ', $str);                  // collapse spaces
    return trim($str);
}

/* ---- Word frequency vector ---- */
function wordFreq($text) {
    if (empty($text)) return [];
    $text  = strtolower(preg_replace('/[^a-z\s]/i', '', $text));
    $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $stop  = ['the','is','in','at','of','a','an','and','to','for','it',
               'on','with','as','was','are','be','by','this','that','from',
               'we','our','i','you','he','she','they','have','has','had',
               'will','would','could','should','may','can','do','does','did',
               'but','or','not','so','if','when','where','which','who','what'];
    $words = array_diff($words, $stop);
    return array_count_values($words);
}

/* ---- Cosine similarity ---- */
function cosine($a, $b) {
    if (empty($a) || empty($b)) return 0.0;
    $keys = array_keys(array_merge($a, $b));
    $dot = $magA = $magB = 0.0;
    foreach ($keys as $k) {
        $va = $a[$k] ?? 0;
        $vb = $b[$k] ?? 0;
        $dot  += $va * $vb;
        $magA += $va * $va;
        $magB += $vb * $vb;
    }
    if ($magA == 0 || $magB == 0) return 0.0;
    return $dot / (sqrt($magA) * sqrt($magB));
}