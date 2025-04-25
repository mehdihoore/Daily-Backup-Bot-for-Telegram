<?php
/**
 * telegram_webhook.php
 *
 * Receives updates from the Telegram Bot API via Webhook.
 * Authorizes requests based on Chat ID stored in a separate file.
 * Triggers the main backup script (`daily_backup.php`) on receiving the /backup command.
 *
 * !!! SECURITY WARNING !!!
 * This script MUST be publicly accessible via HTTPS for Telegram Webhooks.
 * Ensure the triggered backup script and allowed chats file are NOT publicly accessible.
 */

// --- Configuration ---
define('TELEGRAM_BOT_TOKEN', 'YOUR_TELEGRAM_BOT_TOKEN_HERE'); // <-- !!! REPLACE THIS - VERY IMPORTANT !!!

// **IMPORTANT:** Absolute paths to files OUTSIDE the web root. Adjust username/paths.
define('ALLOWED_CHATS_FILE', '/home/your_cpanel_username/secure_scripts/allowed_chats.txt'); // <-- !!! SET CORRECT PATH !!!
define('BACKUP_SCRIPT_PATH', '/home/your_cpanel_username/secure_scripts/daily_backup.php');  // <-- !!! SET CORRECT PATH !!!
// Verify PHP CLI path on your server
define('PHP_EXECUTABLE_PATH', '/usr/bin/php'); // Common path, adjust if needed (e.g., /usr/local/bin/php)

// --- Helper Functions ---

/**
 * Sends a simple text message back to a specific Telegram chat.
 * @param int $chatId Target Chat ID.
 * @param string $messageText Text message to send.
 * @return bool True on success, false on failure.
 */
function sendTelegramMessage(int $chatId, string $messageText): bool {
    if (!defined('TELEGRAM_BOT_TOKEN') || TELEGRAM_BOT_TOKEN === 'YOUR_TELEGRAM_BOT_TOKEN_HERE') {
        error_log("Cannot send message: Telegram Bot Token not configured.");
        return false;
    }
    $apiUrl = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $postData = [ 'chat_id' => $chatId, 'text' => $messageText, 'parse_mode' => 'HTML' ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200) { error_log("Telegram sendMessage failed: HTTP {$httpCode} - cURL Error: {$curlError} - Response: {$response}"); return false; }
    $responseData = json_decode($response, true);
    if (!$responseData || !$responseData['ok']) { error_log("Telegram API Error sending message: " . $response); return false; }
    return true;
}

/**
 * Checks if a given Chat ID is listed in the allowed chats file.
 * @param int $chatId The Chat ID to check.
 * @return bool True if allowed, False otherwise.
 */
function isChatAllowed(int $chatId): bool {
    if (!defined('ALLOWED_CHATS_FILE') || !file_exists(ALLOWED_CHATS_FILE) || !is_readable(ALLOWED_CHATS_FILE)) {
        error_log("Authorization check failed: Allowed chats file path incorrect, not found, or not readable: " . (defined('ALLOWED_CHATS_FILE') ? ALLOWED_CHATS_FILE : 'PATH_NOT_DEFINED'));
        return false; // Fail securely
    }
    // Read file line by line, trimming whitespace and converting to int
    $allowedIds = file(ALLOWED_CHATS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($allowedIds === false) { error_log("Error reading allowed chats file: " . ALLOWED_CHATS_FILE); return false; }
    $allowedIds = array_map('trim', $allowedIds);
    $allowedIds = array_map('intval', $allowedIds);
    return in_array($chatId, $allowedIds, true); // Strict comparison (integer)
}

// --- Main Webhook Logic ---

// Get raw POST data from Telegram
$input = file_get_contents('php://input');
if (!$input) { error_log("Webhook received empty input."); http_response_code(400); exit('No input received.'); }

// Decode JSON update
$update = json_decode($input, true);
if ($update === null && json_last_error() !== JSON_ERROR_NONE) { error_log("Webhook failed to decode JSON: " . json_last_error_msg() . " - Input: " . $input); http_response_code(400); exit('Invalid JSON received.'); }

// Process only message updates
if (!isset($update['message'])) { http_response_code(200); exit('OK'); } // Ignore non-message updates

// Extract essential info
$message = $update['message'];
$chatId = $message['chat']['id'] ?? null;
$messageText = trim($message['text'] ?? '');
$userId = $message['from']['id'] ?? null; // User ID for logging

if (!$chatId || !$userId) { error_log("Webhook received message with missing chat_id or user_id: " . $input); http_response_code(400); exit('Missing chat or user ID.'); }

// 1. Authorization Check
if (!isChatAllowed($chatId)) {
    error_log("Unauthorized access attempt from Chat ID: {$chatId}, User ID: {$userId}");
    // Fail silently to Telegram, but log the attempt.
    http_response_code(200); // Acknowledge receipt to Telegram
    exit('Unauthorized.');
}

// 2. Command Check
if (strtolower($messageText) === '/backup') {
    error_log("Received /backup command from authorized Chat ID: {$chatId}, User ID: {$userId}");

    // Hardcode DB name or retrieve non-sensitively if needed for message
    $dbNameToDisplay = 'alumglas_hpc'; // <-- ADJUST if DB name changes often (better not to)
    sendTelegramMessage($chatId, "Backup initiated for database `{$dbNameToDisplay}`. Please wait...");

    // 3. Trigger the Secure Backup Script Synchronously
    if (!defined('PHP_EXECUTABLE_PATH') || !defined('BACKUP_SCRIPT_PATH') || !file_exists(BACKUP_SCRIPT_PATH)) {
         error_log("Cannot execute backup: PHP executable path or backup script path is not defined or backup script not found.");
         sendTelegramMessage($chatId, "⚠️ Configuration error: Cannot locate backup script.");
         http_response_code(500); // Internal server error
         exit('Configuration Error');
    }

    $command = escapeshellcmd(PHP_EXECUTABLE_PATH) . ' ' . escapeshellarg(BACKUP_SCRIPT_PATH);
    // Execute synchronously (NO '&'), redirect output to prevent webhook issues
    $command .= ' > /dev/null 2>&1';

    error_log("Executing command: " . $command);
    unset($outputLines); // Ensure array is clean
    exec($command, $outputLines, $returnVar); // Runs synchronously

    // Log execution result (the backup script logs its own detailed success/failure)
    if ($returnVar === 0) {
        error_log("Backup script execution triggered successfully (returned 0) by Chat ID: {$chatId}. Backup script will send final files/status.");
        // Don't send another message here - daily_backup.php handles sending files.
    } else {
        // This indicates the PHP script *itself* failed to execute properly OR exited with an error code.
        error_log("Backup script execution FAILED (returned non-zero: {$returnVar}) triggered by Chat ID: {$chatId}. Check backup script logs.");
        sendTelegramMessage($chatId, "⚠️ An error occurred while trying to *run* the backup process (Code: {$returnVar}). Please check server logs.");
    }

} else {
    // Handle unknown commands from authorized users
    error_log("Received unknown command '{$messageText}' from authorized Chat ID: {$chatId}, User ID: {$userId}");
    sendTelegramMessage($chatId, "Sorry, I only understand the `/backup` command.");
}

// Respond 200 OK to Telegram quickly
http_response_code(200);
exit('OK');

?>
