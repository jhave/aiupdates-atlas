<?php
// atlas.php: read-only JSON for atlas.html
//   atlas.php          -> all entries (compact: id, unix, title, summary, link, tags)
//   atlas.php?id=123   -> full description of one entry
//   atlas.php?q=word   -> ids whose title or full description contain the phrase
// GET only. Opens the database read-only. No writes, no sessions.

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
    http_response_code(405);
    echo '{"error":"GET only"}';
    exit;
}

$dbFile = __DIR__ . '/ai_updates.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbFile, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
    ]);
    $pdo->exec('PRAGMA query_only = 1');
} catch (Exception $e) {
    error_log('atlas.php: ' . $e->getMessage());
    http_response_code(500);
    echo '{"error":"Database unavailable"}';
    exit;
}

function plain($html) {
    $t = html_entity_decode((string)$html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = strip_tags($t);
    $t = str_replace("\xC2\xA0", ' ', $t);
    return $t;
}

function summary($desc, $max = 260) {
    $t = trim(plain($desc));
    $parts = preg_split('/\n\s*\n|\n~\n/u', $t, 2);
    $t = preg_replace('/\s+/u', ' ', trim($parts[0] ?? ''));
    if (mb_strlen($t) > $max) {
        $t = mb_substr($t, 0, $max);
        $t = preg_replace('/\s+\S*$/u', '', $t) . '…';
    }
    return $t;
}

// --- one entry, full text ---
if (isset($_GET['id'])) {
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) { http_response_code(400); echo '{"error":"Bad id"}'; exit; }
    $st = $pdo->prepare('SELECT id, description FROM entries WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) { http_response_code(404); echo '{"error":"Not found"}'; exit; }
    header('Cache-Control: public, max-age=86400');
    echo json_encode(['id' => (int)$row[0], 'text' => trim(plain($row[1]))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- full-text search: ids only ---
if (isset($_GET['q'])) {
    $q = trim((string)$_GET['q']);
    if ($q === '' || mb_strlen($q) > 120) { echo '{"ids":[]}'; exit; }
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $st = $pdo->prepare("SELECT id FROM entries WHERE title LIKE ? ESCAPE '\\' OR description LIKE ? ESCAPE '\\'");
    $st->execute([$like, $like]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    header('Cache-Control: public, max-age=600');
    echo json_encode(['ids' => $ids]);
    exit;
}

// --- everything, compact ---
$mtime = @filemtime($dbFile) ?: time();
$etag = '"atlas-' . $mtime . '"';
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
// Always ask the server whether the archive has changed. The ETag makes this cheap:
// when nothing is new the answer is an empty 304 and the browser reuses its copy.
header('Cache-Control: no-cache');
if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }

if (!ini_get('zlib.output_compression') && extension_loaded('zlib')) { ob_start('ob_gzhandler'); }

$rows = [];
foreach ($pdo->query('SELECT id, date_unix, title, description, link, tags FROM entries ORDER BY date_unix DESC, id DESC') as $r) {
    $rows[] = [
        (int)$r[0],
        (int)$r[1],
        trim(preg_replace('/\s+/u', ' ', plain($r[2]))),
        summary($r[3]),
        (string)$r[4],
        (string)$r[5],
    ];
}
echo json_encode(['updated' => $mtime, 'entries' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
