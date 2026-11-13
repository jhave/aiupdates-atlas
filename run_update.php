<?php
// run_update.php (Browser-Friendly & Secure Version - Final)
// This version contains a robust CSV parser to correctly handle multi-line fields.

// --- ERROR HANDLING (production) ---
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// --- TIMEZONE FIX ---
// Sets a default timezone for the script to use.
date_default_timezone_set('UTC');

// --- CONFIGURATION ---
// The key and the sheet URL live in config.php, which is NOT in git.
// See config.example.php. Without a valid config.php the updater refuses to run.
$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : null;
if (!is_array($config)
    || empty($config['secret_key']) || !is_string($config['secret_key'])
    || strlen($config['secret_key']) < 16
    || strpos($config['secret_key'], 'CHANGE-ME') === 0
    || empty($config['google_sheet_url']) || !is_string($config['google_sheet_url'])) {
    http_response_code(500);
    error_log('run_update.php: config.php is missing or incomplete');
    die('Updater is not configured.');
}
define('SECRET_KEY', $config['secret_key']);
define('GOOGLE_SHEET_URL', $config['google_sheet_url']);

// --- BROWSER-FRIENDLY OUTPUT ---
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><title>Archive Updater</title>';
echo '<style>body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background: #f0f2f5; color: #333; line-height: 1.6; margin: 0; padding: 20px; } .container { max-width: 800px; margin: 0 auto; padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); } h1 { color: #1d2129; } hr { border: 0; border-top: 1px solid #ddd; margin: 20px 0; } .success { color: #28a745; font-weight: bold; } .error { color: #dc3545; font-weight: bold; } .info { color: #007bff; } .skipped { color: #ffc107; } code { background: #eee; padding: 2px 5px; border-radius: 4px; } pre { background: #eee; padding: 10px; border-radius: 4px; max-height: 150px; overflow-y: auto; white-space: pre-wrap; word-wrap: break-word; }</style>';
echo '</head><body><div class="container">';
echo '<h1>AI Updates Archive Updater</h1>';
echo "<p>Update process started at " . date('Y-m-d H:i:s') . "</p><hr>";

// --- SECURITY CHECK ---
if (!isset($_GET['key']) || !is_string($_GET['key']) || !hash_equals(SECRET_KEY, $_GET['key'])) {
    http_response_code(403);
    die('<p class="error">Access Denied. A valid security key is required.</p></div></body></html>');
}

echo "<p><strong>Checkpoint 1:</strong> Security key accepted.</p>";

// --- PRE-FLIGHT CHECKS ---
if (!function_exists('curl_init')) {
    die('<p class="error">FATAL ERROR: The cURL extension is not installed or enabled on this server.</p></div></body></html>');
}
echo "<p><strong>Checkpoint 2:</strong> cURL extension is available.</p>";

require_once __DIR__ . '/archive.php';
echo "<p><strong>Checkpoint 3:</strong> <code>archive.php</code> included successfully.</p>";

try {
    echo "<p><strong>Checkpoint 4:</strong> Inside the main <code>try</code> block.</p>";
    $manager = new ArchiveManager();
    $latestDateString = $manager->getLatestDate();
    $latestArchiveDate = null;

    if ($latestDateString) {
        $latestArchiveDate = new DateTime(str_replace(' at ', ' ', $latestDateString));
        echo "<p class='info'>Latest entry in local archive: " . $latestArchiveDate->format('Y-m-d H:i:s') . "</p>";
    } else {
        echo "<p class='info'>Local archive appears to be empty. Fetching all entries.</p>";
    }

    echo "<p><strong>Checkpoint 5:</strong> Fetching data from Google Sheet using cURL...</p>";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, GOOGLE_SHEET_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $sheetCSV = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL Error: " . $error_msg);
    }
    curl_close($ch);

    echo "<p class='success'><strong>Checkpoint 6:</strong> Successfully fetched sheet data.</p>";

    $sheetEntries = parse_csv_from_string($sheetCSV);
    echo "<p><strong>*** Checkpoint 7:</strong> Parsed CSV data. Found " . count($sheetEntries) . " total rows in the sheet.</p>";

    $newEntries = [];
    $skippedRowCount = 0;
    foreach ($sheetEntries as $index => $entry) {
        //echo"<p>READING ***** {$index}: date=" . var_export($entry['Date'], true) . ", link=" . var_export($entry['Link'], true) . "</p>";

        if (empty($entry['Date']) || empty($entry['Link']) || strpos($entry['Date'], ' at ') === false) {
            $skippedRowCount++;

            // Debug:
            error_log("Skipping row {$index}: date=" . var_export($entry['Date'], true) . ", link=" . var_export($entry['Link'], true));


            continue;
        }

        $entryDate = new DateTime(str_replace(' at ', ' ', $entry['Date']));
        if (!$latestArchiveDate || $entryDate > $latestArchiveDate) {
            $newEntries[] = $entry;
        }
    }

    if ($skippedRowCount > 0) {
        echo "<p class='info'>Finished parsing. Skipped a total of " . $skippedRowCount . " invalid or empty rows.</p>";
    }

    if (!empty($newEntries)) {
        $reversedEntries = array_reverse($newEntries);
        echo "TOTAL ENTRIES: " . count($sheetEntries) . "<p class='info'><strong>Checkpoint 8:</strong> Found " . count($newEntries) . " new entries to add. Archiving...</p>";
        $addedCount = $manager->addEntries($reversedEntries);
        echo "<p class='success'>Successfully added " . $addedCount . " new entries to the database.</p>";
    } else {
        echo "<p class='success'><strong>Checkpoint 8:</strong> Archive is already up to date. No new entries found.</p>";
    }

} catch (Exception $e) {
    echo '<p class="error">--- ERROR ---<br>';
    echo "An error occurred: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "File: " . htmlspecialchars($e->getFile()) . " on line " . $e->getLine() . "<br>";
    echo '-------------</p>';
}

echo '<hr><p>Update process finished.</p>';
echo '</div></body></html>';

/**
 * A robust function to parse a CSV string into an array.
 * It correctly handles multi-line fields and other common CSV edge cases.
 * @param string $csv_string The raw CSV data.
 * @return array An array of associative arrays representing the rows.
 */
function parse_csv_from_string($csv_string)
{
    // Remove UTF-8 BOM if present
    if (substr($csv_string, 0, 3) == "\xEF\xBB\xBF") {
        $csv_string = substr($csv_string, 3);
    }

    $data = [];
    // Create a file pointer to a memory stream
    $fp = fopen('php://memory', 'r+');
    // Write the CSV string to the memory stream
    fputs($fp, $csv_string);
    // Rewind the stream pointer to the beginning
    rewind($fp);

    // Read the header row
    $headers = fgetcsv($fp);
    if ($headers === false) {
        fclose($fp);
        return [];
    }

    // Loop through the remaining rows
    while (($row = fgetcsv($fp)) !== false) {
        // This handles cases where a row might have a different number of columns than the header
        if (count($headers) == count($row)) {
            $data[] = array_combine($headers, $row);
        }
    }

    fclose($fp);
    return $data;
}
?>