<?php
/* ============================================================
   EduVault – php/ai_detect.php   *** FIXED VERSION ***
   AI-Generated Content Detection Engine (Pure PHP, No API)

   BUGS FIXED IN THIS VERSION:
   ---------------------------------------------------------------
   BUG 1 – ob_start() was called but never ob_end_clean()'d before
            jsonResponse(). On XAMPP (display_errors=On) any PHP
            Notice or Warning got captured into the output buffer
            and prepended to the JSON string, making it unparseable.
            JSON.parse() then threw, falling into .catch(), which
            showed "Server error during AI detection. Check XAMPP."
            FIX: Removed ob_start(). Added error_reporting(0) +
            ini_set('display_errors','0') so warnings never appear
            in output. Added ob_clean() guard before every exit.

   BUG 2 – saveAIResult() ran ALTER TABLE submissions ADD COLUMN
            inside a try/catch, BUT the outer try/catch in the
            main body caught the PDOException from the ALTER and
            returned a database-error response before the INSERT
            into ai_detection_results even ran.
            FIX: Wrapped ALTER TABLE in its own isolated try/catch
            with a silent continue on "Duplicate column" so it
            never bubbles up to the main handler.

   BUG 3 – If the ai_probability column did not exist yet (user
            imported an old eduvault.sql and skipped migration),
            the SELECT * in the main query returned all columns
            fine, but the UPDATE submissions SET ai_probability=?
            threw "Unknown column" — again caught by the outer
            handler and returned as an error response.
            FIX: Column existence is now checked and created
            BEFORE the main submission SELECT, at startup.

   BUG 4 – str_word_count() on PDF-extracted text containing
            UTF-8 / non-ASCII characters produced PHP Warnings
            ("str_word_count(): Expected a string without null
            bytes") that mixed into output.
            FIX: sanitizeText() strips null bytes and non-printable
            characters before any word/sentence analysis.

   BUG 5 – tokenizeSentences() could return an empty array if
            the text had no capital-letter sentence boundaries
            (e.g. all-lowercase PDF extraction). detectBurstiness()
            and detectSentenceUniformity() called max() on empty
            arrays -> PHP Warning "max(): Array must contain at
            least one element."
            FIX: Added empty-array guards before every max()/min().

   BUG 6 – currentSubIdForAI in ai_detection.js was reset to null
            every time the similarity panel re-initialized because
            loadMySubmissionsForRadar() re-runs on every panel
            switch but setAISubmissionId() is only called AFTER
            loadSimilarity() completes its fetch. During the async
            gap the Run button could be clicked with null ID.
            FIX: The JS now reads the current docSelect value as
            a fallback when currentSubIdForAI is null.
   ============================================================ */

/* Suppress ALL PHP notices/warnings from polluting JSON output */
error_reporting(0);
ini_set('display_errors', '0');

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    safeJson(false, 'Method not allowed.');
}

$subId  = (int)($_POST['submission_id'] ?? 0);
$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'] ?? 'student';

if ($subId <= 0) safeJson(false, 'Invalid submission ID. Please select a submission first.');

try {
    $pdo = getDB();

    /* ---- BUG 3 FIX: Ensure ai_probability column exists FIRST ---- */
    ensureAIColumns($pdo);

    /* ---- Fetch submission ---- */
    if ($role === 'admin') {
        $stmt = $pdo->prepare('SELECT * FROM submissions WHERE id = ? LIMIT 1');
        $stmt->execute([$subId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM submissions WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$subId, $userId]);
    }
    $sub = $stmt->fetch();
    if (!$sub) safeJson(false, 'Submission not found or access denied.');

    $text = trim($sub['extracted_text'] ?? '');

    /* If no stored text, try extracting from file now */
    if (strlen($text) < 100) {
        $filePath = __DIR__ . '/../uploads/' . ($sub['stored_filename'] ?? '');
        if (file_exists($filePath)) {
            $ext  = strtolower(pathinfo($sub['stored_filename'], PATHINFO_EXTENSION));
            $text = extractTextForAI($filePath, $ext);

            /* Cache it for next time */
            if (strlen(trim($text)) > 50) {
                $pdo->prepare('UPDATE submissions SET extracted_text = ? WHERE id = ?')
                    ->execute([$text, $subId]);
            }
        }
    }

    if (strlen(trim($text)) < 50) {
        safeJson(false, 'Not enough text to analyze. The file may be an image-based PDF or the text could not be extracted. Try uploading a text-based PDF or a .docx/.txt file.');
    }

    /* ---- Run all 8 detectors ---- */
    $result = analyzeForAI($text);

    /* ---- Save result to DB ---- */
    saveAIResult($pdo, $subId, $result);

    logActivity($pdo, $userId, 'ai_detect', $subId);

    safeJson(true, 'AI detection complete.', [
        'submission_id'   => $subId,
        'title'           => $sub['title'],
        'ai_probability'  => $result['ai_probability'],
        'verdict'         => $result['verdict'],
        'verdict_label'   => $result['verdict_label'],
        'confidence'      => $result['confidence'],
        'detectors'       => $result['detectors'],
        'flagged_phrases' => $result['flagged_phrases'],
        'word_count'      => $result['word_count'],
        'analysis_note'   => $result['analysis_note'],
    ]);

} catch (PDOException $e) {
    error_log('[EduVault AI Detect] DB Error: ' . $e->getMessage());
    safeJson(false, 'Database error. Make sure the database is set up and run_migration.php if you haven\'t already. Detail: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[EduVault AI Detect] Error: ' . $e->getMessage());
    safeJson(false, 'Server error: ' . $e->getMessage());
}

/* ============================================================
   BUG 1 FIX: safeJson cleans output buffer before sending JSON,
   ensuring no stray warnings/notices prefix the response.
   ============================================================ */
function safeJson(bool $success, string $message, array $data = []): void {
    /* Discard anything that snuck into the output buffer */
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(
        ['success' => $success, 'message' => $message],
        $data
    ));
    exit;
}

/* ============================================================
   BUG 3 FIX: Create missing columns/tables before main logic
   ============================================================ */
function ensureAIColumns(PDO $pdo): void {
    /* Add ai_probability to submissions if missing */
    try {
        $pdo->exec("ALTER TABLE submissions ADD COLUMN ai_probability DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER similarity_score");
    } catch (PDOException $e) {
        /* "Duplicate column name" = already exists, safe to ignore */
        if (strpos($e->getMessage(), 'Duplicate column') === false &&
            strpos($e->getMessage(), "already exists") === false) {
            /* Real error - log but don't crash */
            error_log('[EduVault AI] ALTER warning: ' . $e->getMessage());
        }
    }

    /* Create ai_detection_results table if missing */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_detection_results` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `submission_id`  INT UNSIGNED NOT NULL,
            `ai_probability` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `verdict`        VARCHAR(30)  NOT NULL DEFAULT 'unknown',
            `confidence`     VARCHAR(10)  NOT NULL DEFAULT 'Low',
            `detectors_json` LONGTEXT,
            `flagged_json`   TEXT,
            `analysis_note`  TEXT,
            `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_sub` (`submission_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/* ============================================================
   MAIN ANALYSIS ENGINE
   ============================================================ */
function analyzeForAI(string $rawText): array {
    /* BUG 4 FIX: sanitize text before any analysis */
    $text   = sanitizeText($rawText);
    $words  = tokenizeWords($text);
    $sents  = tokenizeSentences($text);
    $wCount = count($words);

    if ($wCount < 30 || count($sents) < 3) {
        return shortTextResult($wCount);
    }

    $d = [];
    $d['perplexity']    = detectPerplexity($words);
    $d['burstiness']    = detectBurstiness($sents);
    $d['vocabulary']    = detectVocabularyRichness($words);
    $d['uniformity']    = detectSentenceUniformity($sents);
    $d['transitions']   = detectTransitionDensity($text, $sents);
    $d['passive_voice'] = detectPassiveVoice($sents);
    $d['hedging']       = detectHedgingLanguage($text, $words);
    $d['ai_signatures'] = detectAISignatureWords($text, $words);

    $weights = [
        'perplexity'    => 0.20,
        'burstiness'    => 0.18,
        'vocabulary'    => 0.12,
        'uniformity'    => 0.15,
        'transitions'   => 0.10,
        'passive_voice' => 0.08,
        'hedging'       => 0.08,
        'ai_signatures' => 0.09,
    ];

    $aiProb = 0.0;
    foreach ($weights as $key => $w) {
        $aiProb += ($d[$key]['score'] ?? 0) * $w;
    }
    $aiProb = round(min(100, max(0, $aiProb)));

    if ($aiProb >= 75)     { $verdict = 'likely_ai';    $label = 'Likely AI-Generated';    $conf = 'High'; }
    elseif ($aiProb >= 50) { $verdict = 'possibly_ai';  $label = 'Possibly AI-Generated';  $conf = 'Medium'; }
    elseif ($aiProb >= 25) { $verdict = 'mixed';        $label = 'Mixed (AI + Human)';     $conf = 'Medium'; }
    else                   { $verdict = 'likely_human'; $label = 'Likely Human-Written';   $conf = 'High'; }

    return [
        'ai_probability' => $aiProb,
        'verdict'        => $verdict,
        'verdict_label'  => $label,
        'confidence'     => $conf,
        'word_count'     => $wCount,
        'detectors'      => $d,
        'flagged_phrases'=> collectFlaggedPhrases($text),
        'analysis_note'  => generateNote($d, $aiProb),
    ];
}

/* ============================================================
   DETECTOR 1: Perplexity
   ============================================================ */
function detectPerplexity(array $words): array {
    $aiBigrams = [
        'in the','of the','it is','is a','in a','is important','it can be',
        'can be','such as','as well','as a','this is','there are','this can',
        'in order','order to','it should','should be','is used','used to',
        'is the','the use','use of','the process','this process','is essential',
        'plays a','a key','a crucial','a significant','it provides','provides a',
        'furthermore it','in addition','addition to','for example','as such',
    ];

    if (count($words) < 4) return ['score' => 50, 'label' => 'Predictability', 'details' => 'Insufficient data'];

    $bigrams = [];
    for ($i = 0; $i < count($words) - 1; $i++) {
        $bigrams[] = strtolower($words[$i]) . ' ' . strtolower($words[$i + 1]);
    }

    $total   = count($bigrams);
    $matches = 0;
    foreach ($bigrams as $bg) {
        if (in_array($bg, $aiBigrams)) $matches++;
    }

    $ratio = $total > 0 ? ($matches / $total) * 100 : 0;
    $score = min(100, round($ratio * 12));

    return [
        'score'   => $score,
        'label'   => 'Predictability',
        'details' => round($ratio, 1) . '% of word pairs match AI patterns (' . $matches . '/' . $total . ')',
    ];
}

/* ============================================================
   DETECTOR 2: Burstiness
   ============================================================ */
function detectBurstiness(array $sentences): array {
    /* BUG 5 FIX: guard against empty or small arrays */
    $filtered = array_values(array_filter($sentences, fn($s) => strlen(trim($s)) > 0));
    if (count($filtered) < 3) {
        return ['score' => 50, 'label' => 'Burstiness', 'details' => 'Too few sentences to measure'];
    }

    $lengths = array_map(fn($s) => max(1, str_word_count(preg_replace('/[^\x20-\x7E\s]/', '', $s))), $filtered);

    $mean = array_sum($lengths) / count($lengths);
    if ($mean == 0) return ['score' => 50, 'label' => 'Burstiness', 'details' => 'Could not measure'];

    $variance = 0;
    foreach ($lengths as $l) { $variance += pow($l - $mean, 2); }
    $stddev = sqrt($variance / count($lengths));
    $cv     = $stddev / $mean;

    if ($cv >= 0.5)     $score = 10;
    elseif ($cv >= 0.4) $score = 25;
    elseif ($cv >= 0.3) $score = 45;
    elseif ($cv >= 0.2) $score = 65;
    elseif ($cv >= 0.1) $score = 82;
    else                $score = 95;

    return [
        'score'   => $score,
        'label'   => 'Burstiness',
        'details' => 'Sentence length variation: ' . round($cv, 2) . ' (avg ' . round($mean, 1) . ' words). ' .
                     ($cv < 0.3 ? 'Suspiciously uniform — AI indicator.' : 'Natural variation detected.'),
    ];
}

/* ============================================================
   DETECTOR 3: Vocabulary Richness
   ============================================================ */
function detectVocabularyRichness(array $words): array {
    if (count($words) < 20) return ['score' => 50, 'label' => 'Vocabulary Pattern', 'details' => 'Too few words'];

    $lower  = array_map('strtolower', $words);
    $unique = count(array_unique($lower));
    $total  = count($lower);
    $ttr    = $unique / $total;

    $aiVocab = [
        'additionally','furthermore','moreover','however','therefore','consequently',
        'significantly','importantly','essentially','ultimately','specifically',
        'particularly','comprehensive','crucial','vital','fundamental','pivotal',
        'multifaceted','paradigm','leverage','facilitate','implement','utilize',
        'streamline','robust','seamlessly','accordingly','subsequently','inherently',
        'encompass','demonstrate','highlight','underscore','emphasize','noteworthy',
    ];

    $aiWordCount = 0;
    foreach ($lower as $w) {
        if (in_array($w, $aiVocab)) $aiWordCount++;
    }
    $aiWordRatio = $aiWordCount / $total;

    $ttrScore   = $ttr < 0.35 ? 90 : ($ttr < 0.45 ? 70 : ($ttr < 0.55 ? 45 : ($ttr < 0.65 ? 25 : 10)));
    $vocabScore = min(100, round($aiWordRatio * 400));
    $score      = (int)(($ttrScore * 0.5) + ($vocabScore * 0.5));

    return [
        'score'   => $score,
        'label'   => 'Vocabulary Pattern',
        'details' => 'Unique word ratio: ' . round($ttr * 100, 1) . '%. AI signature words: ' .
                     $aiWordCount . ' (' . round($aiWordRatio * 100, 1) . '%)',
    ];
}

/* ============================================================
   DETECTOR 4: Sentence Uniformity
   ============================================================ */
function detectSentenceUniformity(array $sentences): array {
    /* BUG 5 FIX: guard against empty arrays */
    $filtered = array_values(array_filter($sentences, fn($s) => strlen(trim($s)) > 0));
    if (count($filtered) < 4) return ['score' => 40, 'label' => 'Structural Uniformity', 'details' => 'Too few sentences'];

    $startWords = [];
    foreach ($filtered as $s) {
        $wds = explode(' ', trim(strtolower($s)));
        if ($wds) $startWords[] = $wds[0];
    }

    $startFreq    = array_count_values($startWords);
    $totalSents   = count($filtered);
    $maxFreqStart = !empty($startFreq) ? max($startFreq) : 1;
    $startRatio   = $maxFreqStart / $totalSents;

    $lengths = array_map(fn($s) => max(1, str_word_count(preg_replace('/[^\x20-\x7E\s]/', '', $s))), $filtered);

    /* BUG 5 FIX: guard max/min on lengths */
    $maxLen = !empty($lengths) ? max($lengths) : 1;
    $minLen = !empty($lengths) ? min($lengths) : 0;
    $range  = $maxLen > 0 ? ($maxLen - $minLen) / $maxLen : 0;

    $lengthScore = $range < 0.3 ? 85 : ($range < 0.5 ? 60 : ($range < 0.7 ? 35 : 15));
    $startScore  = min(100, round($startRatio * 150));
    $score       = (int)(($lengthScore * 0.6) + ($startScore * 0.4));

    return [
        'score'   => $score,
        'label'   => 'Structural Uniformity',
        'details' => 'Sentence length range: ' . round($range * 100, 0) . '%. ' .
                     'Most common opener used ' . $maxFreqStart . '/' . $totalSents . ' times.',
    ];
}

/* ============================================================
   DETECTOR 5: Transition Density
   ============================================================ */
function detectTransitionDensity(string $text, array $sentences): array {
    $transitions = [
        'in conclusion','in summary','to summarize','in addition','furthermore',
        'moreover','additionally','however','on the other hand','nevertheless',
        'therefore','thus','consequently','as a result','in contrast',
        'similarly','likewise','for example','for instance','specifically',
        'in particular','notably','importantly','first and foremost','lastly',
        'finally','to begin with','first of all','it is worth noting',
        'it should be noted','it is important to','one must consider',
        'this demonstrates','this highlights','this suggests','this shows',
    ];

    $textLower = strtolower($text);
    $found     = 0;
    $foundList = [];
    foreach ($transitions as $t) {
        $cnt = substr_count($textLower, $t);
        if ($cnt > 0) { $found += $cnt; $foundList[] = $t; }
    }

    $sentCount = max(1, count($sentences));
    $density   = $found / $sentCount;
    $score     = min(100, round($density * 180));

    return [
        'score'   => $score,
        'label'   => 'Transition Phrases',
        'details' => $found . ' transition phrases in ' . $sentCount . ' sentences (density: ' . round($density, 2) . '/sentence)',
    ];
}

/* ============================================================
   DETECTOR 6: Passive Voice
   ============================================================ */
function detectPassiveVoice(array $sentences): array {
    $passivePatterns = [
        '/\b(is|are|was|were|be|been|being)\s+([\w]+ed)\b/i',
        '/\b(is|are|was|were)\s+([\w]+en)\b/i',
        '/\bcan be\s+[\w]+ed\b/i',
        '/\bmay be\s+[\w]+ed\b/i',
        '/\bshould be\s+[\w]+ed\b/i',
        '/\bwill be\s+[\w]+ed\b/i',
    ];

    $passive = 0;
    $total   = count($sentences);
    foreach ($sentences as $s) {
        foreach ($passivePatterns as $p) {
            if (@preg_match($p, $s)) { $passive++; break; }
        }
    }

    $ratio = $total > 0 ? $passive / $total : 0;
    $score = min(100, round($ratio * 160));

    return [
        'score'   => $score,
        'label'   => 'Passive Voice',
        'details' => $passive . ' of ' . $total . ' sentences use passive voice (' . round($ratio * 100, 0) . '%)',
    ];
}

/* ============================================================
   DETECTOR 7: Hedging Language
   ============================================================ */
function detectHedgingLanguage(string $text, array $words): array {
    $hedges = [
        'may','might','could','should','would','possibly','perhaps',
        'potentially','likely','generally','typically','often','sometimes',
        'usually','arguably','presumably','apparently','seemingly',
        'it appears','it seems','it may be','it could be','one might',
        'some might','many believe','some argue','it is believed',
        'it has been suggested','research suggests','studies show',
        'evidence suggests','it is widely accepted','it is often argued',
    ];

    $textLower  = strtolower($text);
    $wordCount  = max(1, count($words));
    $hedgeCount = 0;
    foreach ($hedges as $h) {
        $hedgeCount += substr_count($textLower, $h);
    }

    $density = $hedgeCount / ($wordCount / 100);
    $score   = min(100, round($density * 10));

    return [
        'score'   => $score,
        'label'   => 'Hedging Language',
        'details' => $hedgeCount . ' hedging phrases found (' . round($density, 1) . ' per 100 words)',
    ];
}

/* ============================================================
   DETECTOR 8: AI Signature Words
   ============================================================ */
function detectAISignatureWords(string $text, array $words): array {
    $highWeight = [
        'delve','tapestry','nuanced','multifaceted','paramount','meticulous',
        'meticulously','intricate','intricacies','navigating','evolving',
        'pivotal','transformative','groundbreaking','encompassing','fostering',
        'harnessing','leveraging','spearheading','underpinning','synergize',
        'holistic','robust','scalable','actionable','seamlessly','streamline',
        'unlock','revolutionize','reimagine','supercharge','elevate',
    ];
    $medWeight = [
        'crucial','vital','essential','significant','comprehensive','diverse',
        'dynamic','innovative','cutting-edge','state-of-the-art','best practices',
        'key takeaway','game changer','paradigm shift','thought leadership',
        'deep dive','circle back','moving forward','at the end of the day',
        'it is worth noting','it is important to note','in the realm of',
        'in today\'s world','in the modern era','in the digital age',
    ];

    $textLower  = strtolower($text);
    $totalWords = max(1, count($words));
    $sigScore   = 0;
    $foundSig   = [];

    foreach ($highWeight as $w) {
        $c = substr_count($textLower, $w);
        if ($c > 0) { $sigScore += $c * 3; $foundSig[] = $w . '×' . $c; }
    }
    foreach ($medWeight as $w) {
        $c = substr_count($textLower, $w);
        if ($c > 0) { $sigScore += $c * 1.5; $foundSig[] = $w . '×' . $c; }
    }

    $density = $sigScore / ($totalWords / 100);
    $score   = min(100, round($density * 8));

    return [
        'score'   => $score,
        'label'   => 'AI Signature Words',
        'details' => !empty($foundSig)
            ? 'Found: ' . implode(', ', array_slice($foundSig, 0, 6)) . (count($foundSig) > 6 ? '...' : '')
            : 'No AI signature words detected',
    ];
}

/* ============================================================
   Collect flagged AI phrases
   ============================================================ */
function collectFlaggedPhrases(string $text): array {
    $patterns = [
        '/^(In (today\'s|the modern|the digital|our increasingly|the contemporary)[^.]{5,60}\.)/im',
        '/^(It is (important|crucial|essential|worth noting|widely accepted)[^.]{5,60}\.)/im',
        '/^(In (conclusion|summary|this (essay|paper|report|discussion))[^.]{3,60}\.)/im',
        '/(Furthermore|Moreover|Additionally|Consequently|Nevertheless|Thus|Therefore|However)[^.]{10,80}\./i',
        '/(This (essay|paper|report|discussion|analysis) (will|aims to|seeks to|explores?|examines?|investigates?|highlights?)[^.]{5,60}\.)/i',
        '/(It (is|has been|can be) (argued|suggested|noted|observed|demonstrated) that[^.]{5,60}\.)/i',
        '/(Research (suggests?|shows?|indicates?|demonstrates?|has shown)[^.]{5,60}\.)/i',
        '/(In (conclusion|summary|closing)[^.]{5,60}\.)/i',
        '/(Overall[^.]{5,60}\.)/i',
        '/(To (summarize|conclude|sum up)[^.]{5,60}\.)/i',
    ];

    $found = [];
    foreach ($patterns as $p) {
        if (@preg_match_all($p, $text, $m)) {
            foreach ($m[0] as $match) {
                $match = trim($match);
                if (strlen($match) > 20 && strlen($match) < 200) {
                    $found[] = $match;
                }
            }
        }
        if (count($found) >= 6) break;
    }
    return array_slice(array_unique($found), 0, 6);
}

/* ============================================================
   Generate human-readable note
   ============================================================ */
function generateNote(array $detectors, int $aiProb): string {
    $topFlags = [];
    foreach ($detectors as $d) {
        if (($d['score'] ?? 0) >= 65) $topFlags[] = $d['label'];
    }

    if ($aiProb >= 75) {
        $note = 'This text shows strong indicators of AI generation. ';
        if ($topFlags) $note .= 'Key flags: ' . implode(', ', array_slice($topFlags, 0, 3)) . '. ';
        $note .= 'The writing exhibits the predictable structure, uniform sentence lengths, and characteristic vocabulary typical of large language models.';
    } elseif ($aiProb >= 50) {
        $note = 'This text shows moderate AI indicators. ';
        if ($topFlags) $note .= 'Notable flags: ' . implode(', ', array_slice($topFlags, 0, 3)) . '. ';
        $note .= 'The writing may be AI-assisted or significantly edited by AI tools.';
    } elseif ($aiProb >= 25) {
        $note = 'This text shows some AI-like patterns but also genuine human writing characteristics. ';
        $note .= 'It may represent AI-assisted writing with significant human editing.';
    } else {
        $note = 'This text shows predominantly human writing patterns: natural sentence variety, organic vocabulary use, and inconsistent (human-like) structure.';
    }
    return $note;
}

/* ============================================================
   BUG 2 FIX: saveAIResult — each DB operation in its own
   try/catch so one failure doesn't abort everything
   ============================================================ */
function saveAIResult(PDO $pdo, int $subId, array $result): void {
    /* Delete old result */
    try {
        $pdo->prepare('DELETE FROM ai_detection_results WHERE submission_id = ?')->execute([$subId]);
    } catch (PDOException $e) { /* table may not exist yet — already handled in ensureAIColumns */ }

    /* Insert new result */
    try {
        $pdo->prepare(
            'INSERT INTO ai_detection_results
               (submission_id, ai_probability, verdict, confidence,
                detectors_json, flagged_json, analysis_note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $subId,
            $result['ai_probability'],
            $result['verdict'],
            $result['confidence'],
            json_encode($result['detectors']),
            json_encode($result['flagged_phrases']),
            $result['analysis_note'],
        ]);
    } catch (PDOException $e) {
        error_log('[EduVault AI] Insert ai_detection_results failed: ' . $e->getMessage());
    }

    /* BUG 2+3 FIX: Update submissions.ai_probability in its OWN try/catch */
    try {
        $pdo->prepare('UPDATE submissions SET ai_probability = ? WHERE id = ?')
            ->execute([$result['ai_probability'], $subId]);
    } catch (PDOException $e) {
        /* Column still missing despite ensureAIColumns — log and continue */
        error_log('[EduVault AI] Could not update ai_probability: ' . $e->getMessage());
    }
}

/* ============================================================
   Text utilities
   ============================================================ */

/* BUG 4 FIX: sanitizeText strips null bytes and non-printable
   chars that cause str_word_count() warnings */
function sanitizeText(string $text): string {
    /* Remove null bytes */
    $text = str_replace("\0", '', $text);
    /* Keep only printable ASCII + standard whitespace + extended Latin */
    $text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\xA0-\xFF]/', ' ', $text);
    $text = preg_replace('/\r\n|\r/', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    return trim($text);
}

function normalizeText(string $text): string {
    return sanitizeText($text);
}

function tokenizeWords(string $text): array {
    $text  = preg_replace('/[^a-zA-Z\s\'-]/', ' ', $text);
    $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_filter($words, fn($w) => strlen($w) >= 2));
}

function tokenizeSentences(string $text): array {
    /* Split on sentence-ending punctuation + whitespace before capital */
    $sents = preg_split('/(?<=[.!?])\s+(?=[A-Z\d])/', $text, -1, PREG_SPLIT_NO_EMPTY);

    /* Fallback: split on double newlines if no sentence boundaries found */
    if (count($sents) < 3) {
        $sents = preg_split('/\n{2,}/', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    /* Fallback 2: split on single newlines */
    if (count($sents) < 3) {
        $sents = preg_split('/\n/', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    return array_values(array_filter(
        array_map('trim', $sents),
        fn($s) => str_word_count(preg_replace('/[^\x20-\x7E\s]/', '', $s)) >= 3
    ));
}

function shortTextResult(int $wCount): array {
    return [
        'ai_probability' => 0,
        'verdict'        => 'insufficient',
        'verdict_label'  => 'Insufficient Text',
        'confidence'     => 'Low',
        'word_count'     => $wCount,
        'detectors'      => [],
        'flagged_phrases'=> [],
        'analysis_note'  => 'Not enough text to analyze (' . $wCount . ' words found). Minimum 30 words required. If this is a PDF, it may be image-based and not text-extractable.',
    ];
}

/* ============================================================
   Text extraction (cross-platform, no shell_exec)
   ============================================================ */
function extractTextForAI(string $path, string $ext): string {
    switch ($ext) {
        case 'txt':
            return sanitizeText((string)@file_get_contents($path));

        case 'docx':
            if (!class_exists('ZipArchive')) return '';
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) return '';
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if (!$xml) return '';
            $xml = str_replace(['</w:p>', '</w:r>', '<w:br', '<w:tab'], [" \n", ' ', ' <w:br', ' <w:tab'], $xml);
            return sanitizeText(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));

        case 'pdf':
            return extractPdfText($path);

        case 'doc':
            $raw = @file_get_contents($path);
            if (!$raw) return '';
            preg_match_all('/[ -~]{4,}/', $raw, $m);
            return sanitizeText(implode(' ', $m[0]));

        default:
            return '';
    }
}

function extractPdfText(string $path): string {
    $raw = @file_get_contents($path);
    if (!$raw || strlen($raw) < 10) return '';

    $text = '';

    /* Strategy 1: decompress FlateDecode streams then extract BT…ET */
    $sources = [$raw];
    preg_match_all('/stream\r?\n([\s\S]*?)\r?\nendstream/m', $raw, $streams);
    foreach ($streams[1] as $s) {
        $dc = @gzuncompress($s);
        if ($dc === false) $dc = @gzinflate(substr($s, 2));
        if ($dc !== false) $sources[] = $dc;
    }

    foreach ($sources as $src) {
        preg_match_all('/BT[\s\S]*?ET/m', $src, $btBlocks);
        foreach ($btBlocks[0] as $block) {
            /* Tj operator */
            preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*(?:Tj|\'|")/s', $block, $tj);
            foreach ($tj[1] as $t) { $text .= decodePdfStr($t) . ' '; }
            /* TJ array operator */
            preg_match_all('/\[([\s\S]*?)\]\s*TJ/s', $block, $TJ);
            foreach ($TJ[1] as $t) {
                preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/', $t, $parts);
                foreach ($parts[1] as $p) { $text .= decodePdfStr($p) . ' '; }
            }
        }
    }

    /* Strategy 2: extract printable runs as fallback */
    if (strlen(trim($text)) < 100) {
        foreach ($sources as $src) {
            preg_match_all('/[ -~]{5,}/', $src, $runs);
            $text .= implode(' ', $runs[0]) . ' ';
        }
    }

    return sanitizeText($text);
}

function decodePdfStr(string $s): string {
    $s = preg_replace_callback('/\\\\([0-7]{1,3}|n|r|t|b|f|\\\\|\(|\))/', function ($m) {
        $c = $m[1];
        if (is_numeric($c[0])) return chr(octdec($c));
        $map = ['n' => ' ', 'r' => ' ', 't' => ' ', 'b' => '', 'f' => '', '\\' => '\\', '(' => '(', ')' => ')'];
        return $map[$c] ?? $c;
    }, $s);
    return preg_replace('/[^\x20-\x7E]/', '', $s);
}