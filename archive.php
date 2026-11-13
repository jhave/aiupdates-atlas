<?php
// archive.php (Final Version)
// This script now contains a check to prevent it from outputting JSON
// when it is included by another script like run_update.php.

// --- ERROR HANDLING (production) ---
// Errors are logged to error_log, never shown to visitors.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// --- CONFIGURATION ---
define('DB_FILE', __DIR__ . '/ai_updates.sqlite');

// --- CLASSES ---

class ArchiveDB extends PDO
{
    public function __construct($file)
    {
        parent::__construct('sqlite:' . $file);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
}

class ArchiveManager
{
    private $pdo;

    public function __construct()
    {
        try {
            $this->pdo = new ArchiveDB(DB_FILE);
            $this->init();
        } catch (PDOException $e) {
            // This will only be triggered if the script is accessed directly.
            http_response_code(500);
            error_log('ArchiveDB: ' . $e->getMessage());
            echo json_encode(['error' => 'Database connection failed']);
            exit;
        }
    }

    public function init()
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                description TEXT,
                link TEXT UNIQUE,
                tags TEXT,
                date_saved TEXT,
                date_unix INTEGER
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_date_unix ON entries (date_unix)');
    }

    public function getEntries($page, $limit, $search, $filterTag)
    {
        $offset = ($page - 1) * $limit;
        $baseQuery = 'FROM entries';
        $whereClauses = [];
        $params = [];

        if (!empty($search)) {
            $whereClauses[] = '(title LIKE :search OR description LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        if (!empty($filterTag)) {
            $whereClauses[] = "tags LIKE :filterTag";
            $params[':filterTag'] = '%' . $filterTag . '%';
        }

        if (!empty($whereClauses)) {
            $baseQuery .= ' WHERE ' . implode(' AND ', $whereClauses);
        }
        
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) as count $baseQuery");
        $countStmt->execute($params);
        $totalItems = (int) $countStmt->fetchColumn();

        $dataQuery = "SELECT * $baseQuery ORDER BY date_unix DESC, id DESC LIMIT :limit OFFSET :offset";
        $dataStmt = $this->pdo->prepare($dataQuery);
        
        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value);
        }
        $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        $dataStmt->execute();
        $data = $dataStmt->fetchAll();

        return [
            'data' => $data,
            'pagination' => [
                'totalItems' => $totalItems,
                'totalPages' => ceil($totalItems / $limit),
                'currentPage' => $page,
                'itemsPerPage' => $limit
            ]
        ];
    }
    
    public function getLatestDate()
    {
        $stmt = $this->pdo->query('SELECT date_saved FROM entries ORDER BY date_unix DESC, id DESC LIMIT 1');
        return $stmt->fetchColumn() ?: null;
    }

    public function addEntries($entries)
    {
        $sql = 'INSERT OR IGNORE INTO entries (title, description, link, tags, date_saved, date_unix) VALUES (:title, :description, :link, :tags, :date_saved, :date_unix)';
        $stmt = $this->pdo->prepare($sql);
        
        $this->pdo->beginTransaction();
        $count = 0;
        foreach ($entries as $entry) {
            if (empty($entry['Link'])) continue;

            $dateString = str_replace(' at ', ' ', $entry['Date'] ?? '');
            $unixTimestamp = strtotime($dateString);

            $stmt->bindValue(':title', $entry['Title'] ?? '');
            $stmt->bindValue(':description', $entry['Description'] ?? '');
            $stmt->bindValue(':link', $entry['Link']);
            $stmt->bindValue(':tags', $entry['Tag'] ?? '');
            $stmt->bindValue(':date_saved', $entry['Date'] ?? '');
            $stmt->bindValue(':date_unix', $unixTimestamp, PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                $count += $stmt->rowCount();
            }
        }
        $this->pdo->commit();
        return $count;
    }
}


// --- ROUTER ---
// This is the crucial fix. This block of code will now ONLY run if 'archive.php'
// is the script being accessed directly by the browser. It will NOT run when
// included by 'run_update.php'.
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    header('Content-Type: application/json');
    
    $manager = new ArchiveManager();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Clamp all input: prevents division-by-zero fatal errors and
        // oversized responses (memory exhaustion), both of which are
        // trigger conditions for CVE-2026-109618 / CVE-2026-109619.
        $page = max(1, min(100000, (int) ($_GET['page'] ?? 1)));
        $limit = max(1, min(1000, (int) ($_GET['limit'] ?? 50)));
        $search = substr(is_string($_GET['search'] ?? null) ? $_GET['search'] : '', 0, 200);
        $filterTag = substr(is_string($_GET['filterTag'] ?? null) ? $_GET['filterTag'] : '', 0, 100);

        try {
            echo json_encode($manager->getEntries($page, $limit, $search, $filterTag));
        } catch (Throwable $e) {
            error_log('archive.php: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Server error']);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
}
?>
