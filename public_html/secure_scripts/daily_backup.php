<?php
/**
 * daily_backup.php
 *
 * Creates MySQL dump (.sql.gz) and CSV export (.csv) of a database.
 * Sends backup files to a specified Telegram Chat ID.
 * Designed to be run via CLI (cron job) or triggered by another script.
 *
 * !!! SECURITY WARNING !!!
 * This script should be placed OUTSIDE the web server's document root.
 * Consider using environment variables or more secure methods for credentials.
 */

// --- Configuration ---

// Database Configuration
define('DB_HOST', 'localhost');                     // <-- Your DB Host
define('DB_NAME', 'alumglas_hpc');                 // <-- Your DB Name
define('DB_RO_USER', 'alumglas_gem');              // <-- Your Read-Only DB User
define('DB_RO_PASS', 'YOUR_READ_ONLY_DB_PASSWORD_HERE'); // <-- !!! REPLACE THIS - VERY IMPORTANT !!!

// Telegram Configuration
define('TELEGRAM_BOT_TOKEN', 'YOUR_TELEGRAM_BOT_TOKEN_HERE'); // <-- !!! REPLACE THIS - VERY IMPORTANT !!!
define('TELEGRAM_CHAT_ID', 'YOUR_TELEGRAM_CHAT_ID_HERE');     // <-- !!! REPLACE THIS (Numeric ID) !!!

// Backup Configuration
define('BACKUP_DIR', __DIR__ . '/backups');       // Temp storage relative to this script
define('MYSQLDUMP_PATH', '/usr/bin/mysqldump');   // Verify path (or use /usr/bin/mariadb-dump)
define('DB_TO_BACKUP', DB_NAME);

// --- Helper Functions ---

/**
 * Connects to the database using Read-Only credentials.
 * Exits script on failure in CLI context.
 * @return PDO|null PDO connection object or null on failure (though script exits).
 */
function connectDB_RO(): ?PDO {
    try {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_RO_USER, DB_RO_PASS, $options);
        return $pdo;
    } catch (PDOException $e) {
        logError("Read-only database connection failed: " . $e->getMessage());
        error_log("FATAL: Cannot connect to DB (RO). Exiting backup script.");
        exit(1); // Exit CLI script with error status
    }
}

/**
 * Logs an error message to PHP's error log and a custom file.
 * @param string $message The error message.
 */
function logError(string $message): void {
    $logDir = __DIR__ . '/logs';
    $logFile = $logDir . '/backup_error.log';
    $timestamp = date('Y-m-d H:i:s');
    // Ensure log directory exists
    if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
    // Log to PHP's main error log first
    error_log("BACKUP SCRIPT ERROR: {$timestamp} - {$message}");
    // Attempt to write to custom log file
    if (is_dir($logDir) && is_writable($logDir)) {
        try { file_put_contents($logFile, "[{$timestamp}] ERROR: {$message}\n", FILE_APPEND | LOCK_EX); }
        catch (Exception $e) { error_log("Failed to write to custom log file {$logFile}: " . $e->getMessage()); }
    } else { error_log("Custom log directory not found or not writable: {$logDir}"); }
    // Echo errors for visibility when run directly or via basic cron
    echo "[{$timestamp}] ERROR: {$message}\n";
}

/**
 * Logs an informational message (outputs to STDOUT).
 * @param string $message The informational message.
 */
function logMessage(string $message): void {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] INFO: {$message}\n";
    // Optional: Add file logging for info messages here if needed
}

/**
 * Sends a file to Telegram using the Bot API via cURL.
 * @param string $filePath Full path to the file to send.
 * @param string $caption Optional caption for the file.
 * @return bool True on success, false on failure.
 */
function sendToTelegram(string $filePath, string $caption = ''): bool {
    if (!file_exists($filePath)) { logError("File not found for Telegram send: " . $filePath); return false; }
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID') || strpos(TELEGRAM_BOT_TOKEN, ':') === false || empty(TELEGRAM_CHAT_ID) || TELEGRAM_BOT_TOKEN === 'YOUR_TELEGRAM_BOT_TOKEN_HERE') { logError("Telegram Bot Token or Chat ID is not configured correctly."); return false; }

    $apiUrl = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendDocument';
    $postData = [ 'chat_id' => TELEGRAM_CHAT_ID, 'document' => new CURLFile(realpath($filePath)), 'caption' => $caption, 'disable_notification' => false ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 90); // Slightly longer timeout for potentially larger files
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) { logError("cURL Error sending to Telegram: " . $curlError); return false; }
    $responseData = json_decode($response, true);
    if ($httpCode !== 200 || !$responseData || !$responseData['ok']) { logError("Telegram API Error (HTTP {$httpCode}): " . $response); return false; }

    logMessage("Successfully sent " . basename($filePath) . " to Telegram chat ID " . TELEGRAM_CHAT_ID);
    return true;
}

// --- Main Backup Logic ---

// Set timezone (essential for correct timestamps)
// Find your timezone: https://www.php.net/manual/en/timezones.php
date_default_timezone_set('Asia/Tehran'); // <-- ADJUST IF NEEDED

logMessage("Starting backup process for database: " . DB_TO_BACKUP);

// 1. Prepare Backup Directory
if (!is_dir(BACKUP_DIR)) {
    logMessage("Attempting to create backup directory: " . BACKUP_DIR);
    if (!mkdir(BACKUP_DIR, 0755, true)) { logError("Failed to create backup directory: " . BACKUP_DIR); exit(1); }
    logMessage("Created backup directory: " . BACKUP_DIR);
}
if (!is_writable(BACKUP_DIR)) { logError("Backup directory is not writable: " . BACKUP_DIR); exit(1); }

// 2. Generate Filenames
$timestamp = date('Ymd_His');
$dbBackupFile = BACKUP_DIR . '/' . DB_TO_BACKUP . '_backup_' . $timestamp . '.sql';
$csvExportFile = BACKUP_DIR . '/' . DB_TO_BACKUP . '_export_' . $timestamp . '.csv';
$gzDbBackupFile = $dbBackupFile . '.gz';

// 3. Create Database SQL Dump (Separated Commands)
logMessage("Creating SQL dump file: " . basename($dbBackupFile));
// Run mysqldump FIRST
$dumpCommand = sprintf(
    '%s --user=%s --password=%s --host=%s --single-transaction --skip-lock-tables --result-file=%s %s',
    escapeshellcmd(MYSQLDUMP_PATH), escapeshellarg(DB_RO_USER), escapeshellarg(DB_RO_PASS),
    escapeshellarg(DB_HOST), escapeshellarg($dbBackupFile), escapeshellarg(DB_TO_BACKUP)
);
$logDumpCommand = sprintf( // Log command without password
    '%s --user=%s --password=*** --host=%s --single-transaction --skip-lock-tables --result-file=%s %s',
    escapeshellcmd(MYSQLDUMP_PATH), escapeshellarg(DB_RO_USER), escapeshellarg(DB_HOST),
    escapeshellarg($dbBackupFile), escapeshellarg(DB_TO_BACKUP)
);
logMessage("Executing mysqldump command: " . $logDumpCommand);
unset($dumpOutput);
exec($dumpCommand . ' 2>&1', $dumpOutput, $dumpReturnVar); // Capture stderr
// Check mysqldump result CAREFULLY
if ($dumpReturnVar !== 0 || !file_exists($dbBackupFile) || filesize($dbBackupFile) === 0) {
    $fileExists = file_exists($dbBackupFile) ? 'Yes' : 'No';
    $fileSize = $fileExists === 'Yes' ? filesize($dbBackupFile) : 'N/A';
    logError("mysqldump command failed or produced empty/no file. Exit code: {$dumpReturnVar}. File exists: {$fileExists}. File size: {$fileSize}. Output: " . implode("\n", $dumpOutput));
    if (file_exists($dbBackupFile)) { @unlink($dbBackupFile); }
    exit(1);
}
logMessage("mysqldump command completed successfully. SQL file created: " . basename($dbBackupFile));

// Run gzip SECOND, only if mysqldump succeeded
logMessage("Gzipping SQL file: " . basename($gzDbBackupFile));
$gzipCommand = sprintf('gzip %s', escapeshellarg($dbBackupFile));
logMessage("Executing gzip command: " . $gzipCommand);
unset($gzipOutput);
exec($gzipCommand . ' 2>&1', $gzipOutput, $gzipReturnVar); // Capture stderr
// Check gzip result
if ($gzipReturnVar !== 0 || !file_exists($gzDbBackupFile)) {
    logError("gzip command failed or .gz file not found. Exit code: {$gzipReturnVar}. Output: " . implode("\n", $gzipOutput));
    if (file_exists($dbBackupFile)) { @unlink($dbBackupFile); } // Clean up original .sql if gzip failed
    exit(1);
}
logMessage("SQL dump created and gzipped successfully: " . basename($gzDbBackupFile));


// 4. Create Full Database CSV Export
logMessage("Creating CSV export: " . basename($csvExportFile));
$pdo = null; $csvHandle = null; $csvCreated = false;
try {
    $pdo = connectDB_RO();
    if (!$pdo) { throw new Exception("Failed to get read-only DB connection for CSV export."); }
    $tablesStmt = $pdo->query('SHOW TABLES');
    $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($tables)) { logMessage("No tables found in the database " . DB_TO_BACKUP . ". Skipping CSV export."); $csvCreated = false; }
    else {
        $csvHandle = fopen($csvExportFile, 'w');
        if ($csvHandle === false) { throw new Exception("Failed to open CSV file for writing: " . $csvExportFile); }
        fwrite($csvHandle, "\xEF\xBB\xBF"); // UTF-8 BOM
        foreach ($tables as $table) {
            logMessage("Exporting table to CSV: " . $table);
            try {
                $stmt = $pdo->query("SELECT * FROM `" . $table . "`");
                $header = []; $colCount = $stmt->columnCount();
                if ($colCount > 0) {
                    for ($i = 0; $i < $colCount; $i++) { $meta = $stmt->getColumnMeta($i); $header[] = $meta['name'] ?? "unknown_col_{$i}"; }
                    fputcsv($csvHandle, $header);
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $orderedRow = []; foreach($header as $colName) { $orderedRow[] = $row[$colName] ?? null; } fputcsv($csvHandle, $orderedRow); }
                } else { fwrite($csvHandle, "# No columns found for table: " . $table . "\n"); }
                $stmt->closeCursor(); unset($stmt);
            } catch (PDOException $tableEx) { logError("Error exporting table '{$table}' to CSV: " . $tableEx->getMessage()); fwrite($csvHandle, "# ERROR exporting table: " . $table . "\n"); }
             fwrite($csvHandle, "\n");
        }
        fclose($csvHandle); $csvHandle = null;
        logMessage("CSV export created successfully: " . basename($csvExportFile));
        $csvCreated = true;
    }
} catch (PDOException $e) { logError("Database error during CSV export preparation: " . $e->getMessage()); if ($csvHandle) @fclose($csvHandle); if (file_exists($csvExportFile)) @unlink($csvExportFile); if (file_exists($gzDbBackupFile)) @unlink($gzDbBackupFile); exit(1); }
catch (Exception $e) { logError("General error during CSV export: " . $e->getMessage()); if ($csvHandle) @fclose($csvHandle); if (file_exists($csvExportFile)) @unlink($csvExportFile); if (file_exists($gzDbBackupFile)) @unlink($gzDbBackupFile); exit(1); }
finally { $pdo = null; }

// 5. Send Files to Telegram
logMessage("Sending files to Telegram...");
$sqlSent = false; $csvSent = false;
if (file_exists($gzDbBackupFile)) {
    $sqlCaption = "SQL Backup (" . DB_TO_BACKUP . ") - " . date('Y-m-d H:i:s T');
    if (sendToTelegram($gzDbBackupFile, $sqlCaption)) { $sqlSent = true; logMessage("SQL backup sent to Telegram."); }
    else { logError("Failed to send SQL backup to Telegram."); }
} else { logError("SQL backup file does not exist, cannot send to Telegram: " . $gzDbBackupFile); $sqlSent = false; }

if ($csvCreated && file_exists($csvExportFile)) {
    $csvCaption = "CSV Export (" . DB_TO_BACKUP . ") - " . date('Y-m-d H:i:s T');
    if (sendToTelegram($csvExportFile, $csvCaption)) { $csvSent = true; logMessage("CSV export sent to Telegram."); }
    else { logError("Failed to send CSV export to Telegram."); }
} elseif ($csvCreated && !file_exists($csvExportFile)) { logError("CSV export file should exist but not found: " . $csvExportFile); $csvSent = false; }
elseif (!$csvCreated) { logMessage("CSV file was not created, nothing to send."); $csvSent = true; }

// 6. Cleanup Local Backup Files
logMessage("Cleaning up local backup files...");
$sqlCleaned = false; $csvCleaned = false;
if (file_exists($gzDbBackupFile)) {
    if (unlink($gzDbBackupFile)) { logMessage("Deleted local SQL backup: " . basename($gzDbBackupFile)); $sqlCleaned = true; }
    else { logError("Failed to delete local SQL backup: " . basename($gzDbBackupFile)); }
} else { logMessage("Local SQL backup not found for cleanup."); $sqlCleaned = true; } // Assume cleaned if not found

if (file_exists($csvExportFile)) {
    if (unlink($csvExportFile)) { logMessage("Deleted local CSV export: " . basename($csvExportFile)); $csvCleaned = true; }
    else { logError("Failed to delete local CSV export: " . basename($csvExportFile)); }
} else { logMessage("Local CSV export not found for cleanup."); $csvCleaned = true; } // Assume cleaned if not found

// 7. Final Status
if ($sqlSent && $csvSent && $sqlCleaned && $csvCleaned) {
    logMessage("Backup process completed successfully.");
    exit(0); // Success
} else {
    logError("Backup process completed with errors. Sent SQL: " . ($sqlSent?'Y':'N') . ", Sent CSV: " . ($csvSent?'Y':'N') . ", Cleaned SQL: " . ($sqlCleaned?'Y':'N') . ", Cleaned CSV: " . ($csvCleaned?'Y':'N'));
    exit(1); // Indicate error
}

?>
