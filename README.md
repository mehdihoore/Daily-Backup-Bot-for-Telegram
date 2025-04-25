# PHP MySQL Daily Backup Bot for Telegram

This project provides two PHP scripts to automate daily backups of a MySQL database:

1.  **`daily_backup.php`**: Creates a compressed SQL dump (`.sql.gz`) and a full CSV export (`.csv`) of a specified database. It sends these files to a designated Telegram chat via a Telegram Bot. This script is designed to be run via **cron job** and can also be triggered on demand.
2.  **`telegram_webhook.php`**: Acts as a webhook handler for a Telegram Bot. It listens for a `/backup` command from authorized Telegram chats (defined in `allowed_chats.txt`) and triggers `daily_backup.php` to perform an instant backup.

## Features

*   Daily automated backups via Cron.
*   On-demand backups via Telegram `/backup` command.
*   Generates both `.sql.gz` (compressed SQL dump) and `.csv` (full DB export).
*   Uses a read-only MySQL user for safety during backup.
*   Sends backup files directly to a specified Telegram chat.
*   Authorizes Telegram commands based on Chat ID.
*   Includes basic logging for troubleshooting.
*   Cleans up temporary backup files after sending.

## Prerequisites

*   PHP (tested with 7.x, 8.x) with `curl` and `pdo_mysql` extensions enabled.
*   A MySQL or MariaDB database.
*   `mysqldump` or `mariadb-dump` command-line utility accessible by the PHP script runner.
*   A web server (like Apache, Nginx) capable of running PHP for the webhook handler.
*   HTTPS enabled for your domain (required for Telegram Webhooks).
*   A Telegram Bot: Create one using @BotFather on Telegram and get the **Bot Token**.
*   Your Telegram **Chat ID**: Get this by messaging @userinfobot on Telegram.
*   Shell access (SSH/Terminal) or cPanel access with Cron Jobs and File Manager.

## Security Warnings ⚠️

*   **Placement:** `daily_backup.php` and `allowed_chats.txt` contain sensitive information (DB credentials) and logic. They **MUST** be placed **OUTSIDE** your web server's public document root (`public_html`, `www`, etc.). Only `telegram_webhook.php` should be publicly accessible via HTTPS.
*   **Credentials:** This script currently uses hardcoded database passwords and Telegram tokens. **This is NOT recommended for production.** Consider using:
    *   Environment variables (more secure).
    *   A separate, non-web-accessible configuration file with strict permissions.
    *   A MySQL credentials file (`~/.my.cnf`) for `mysqldump` to avoid putting the password on the command line.
*   **Bot Token:** Protect your Telegram Bot Token like a password. Anyone with the token can control your bot.
*   **Permissions:** Ensure file and directory permissions are set correctly to prevent unauthorized access while allowing the scripts and web server user to function.

## Setup Instructions

1.  **Create Telegram Bot:**
    *   Talk to @BotFather on Telegram.
    *   Create a new bot (`/newbot`).
    *   Note down the **HTTP API Token** (e.g., `123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11`).

2.  **Get Your Chat ID:**
    *   Talk to @userinfobot on Telegram.
    *   Note down your numeric **Chat ID**.

3.  **Prepare Files & Directories:**
    *   Create a secure directory **outside** your `public_html`, for example, `/home/your_cpanel_username/secure_scripts/`.
    *   Place `daily_backup.php` inside this secure directory.
    *   Create `allowed_chats.txt` inside this secure directory.
    *   Place `telegram_webhook.php` inside your `public_html` directory (or a subdirectory like `public_html/bot/`).
    *   The `daily_backup.php` script will attempt to create `backups` and `logs` subdirectories within its own directory (`secure_scripts/`). Ensure the `secure_scripts` directory is writable by the user running the cron job *and* the web server user (permissions `755` or `775` might be needed).

4.  **Configure `daily_backup.php`:**
    *   Open `/home/your_cpanel_username/secure_scripts/daily_backup.php`.
    *   Fill in the `define` constants at the top:
        *   `DB_HOST`, `DB_NAME`
        *   `DB_RO_USER` (Create a dedicated read-only MySQL user with `SELECT` and `SHOW TABLES` privileges on your database).
        *   `DB_RO_PASS` (**Replace placeholder!**)
        *   `TELEGRAM_BOT_TOKEN` (**Replace placeholder!**)
        *   `TELEGRAM_CHAT_ID` (**Replace placeholder!**)
        *   `MYSQLDUMP_PATH` (Verify the path to `mysqldump` or `mariadb-dump` on your server).
        *   Adjust `date_default_timezone_set()`.

5.  **Configure `telegram_webhook.php`:**
    *   Open `/path/to/public_html/telegram_webhook.php`.
    *   Fill in the `define` constants at the top:
        *   `TELEGRAM_BOT_TOKEN` (**Replace placeholder!**)
        *   `ALLOWED_CHATS_FILE` (Set the **absolute path** to your `allowed_chats.txt`).
        *   `BACKUP_SCRIPT_PATH` (Set the **absolute path** to your `daily_backup.php`).
        *   `PHP_EXECUTABLE_PATH` (Verify the path to the PHP CLI executable).
    *   Update the hardcoded database name in the `sendTelegramMessage` call within the `/backup` command logic if needed.

6.  **Configure `allowed_chats.txt`:**
    *   Open `/home/your_cpanel_username/secure_scripts/allowed_chats.txt`.
    *   Add your numeric Telegram Chat ID on the first line.
    *   Add other authorized Chat IDs on subsequent lines if needed.

7.  **Set File Permissions:**
    *   `secure_scripts/daily_backup.php`: `640` or `600` (Readable by owner/group or just owner).
    *   `secure_scripts/allowed_chats.txt`: `644` (Readable by web server).
    *   `public_html/telegram_webhook.php`: `644`.
    *   `secure_scripts/`: `755` or `775` (Writable by script runners).

8.  **Set Telegram Webhook:**
    *   You need to tell Telegram where to send updates (your webhook handler URL). **Do this only ONCE.**
    *   Get the **HTTPS URL** for `telegram_webhook.php` (e.g., `https://yourdomain.com/telegram_webhook.php`).
    *   **Method A (CLI/Terminal):**
        ```bash
        curl -F "url=YOUR_HTTPS_WEBHOOK_URL" https://api.telegram.org/botYOUR_TELEGRAM_BOT_TOKEN/setWebhook
        ```
        Replace the URL and Token. Look for `{"ok":true,"result":true,...}`.
    *   **Method B (Temporary PHP Script):** See previous chat history for the `reset_webhook.php` script example. Upload it, run it via browser **once**, check the output, then **delete the temporary script**.
    *   **Verify:** Visit `https://api.telegram.org/botYOUR_TELEGRAM_BOT_TOKEN/getWebhookInfo` in your browser. Check that the `"url"` is correct and there are no recent `"last_error_message"`.

9.  **Set up Cron Job (for Daily Backup):**
    *   Use your hosting control panel (cPanel -> Cron Jobs) or `crontab -e` via SSH.
    *   Add a job to run `daily_backup.php` daily at your preferred time (e.g., 3:00 AM).
    *   **Command:**
        ```bash
        # Example: Run daily at 3:00 AM, log output
        0 3 * * * /usr/bin/php /home/your_cpanel_username/secure_scripts/daily_backup.php >> /home/your_cpanel_username/secure_scripts/logs/cron_backup.log 2>&1
        ```
        *   Adjust the PHP path (`/usr/bin/php`).
        *   Use the **absolute path** to `daily_backup.php`.
        *   Use the **absolute path** for the log file. `>>` appends, `2>&1` redirects errors to the log.

## Usage

*   **Automatic:** The cron job will run daily at the scheduled time, sending backups to Telegram.
*   **Manual:** Send the command `/backup` to your Telegram bot from an authorized chat. You should receive a confirmation, followed by the backup files shortly after.

## Troubleshooting

*   **Check Server Error Logs:** Your primary tool. Look in cPanel -> Metrics -> Errors for PHP errors from either script.
*   **Check Script Logs:** Look in `/home/your_cpanel_username/secure_scripts/logs/` for `backup_error.log` (created by `daily_backup.php`) and `cron_backup.log` (created by the cron job redirection).
*   **Webhook Issues:** Use the `/getWebhookInfo` API link to check the status and errors reported by Telegram. Ensure the URL is correct.
*   **Permissions:** Double-check file and directory permissions. The user running the web server (for webhook triggers) and the user running cron jobs must have appropriate read/write/execute permissions.
*   **Paths:** Ensure all absolute paths defined in the scripts are correct for your server environment.
