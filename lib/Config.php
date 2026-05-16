<?php
/**
 * ============================================================
 * Config.php — Central Configuration Manager
 * ============================================================
 * 
 * 🏗️ Architecture Layer: 0 - Fundamentals
 * 📦 Part of: Khashayar YouTube Downloader - Queue System
 * 🔗 Dependency: None (standalone - all files depend on this)
 * 
 * 🎯 Purpose:
 *   • Single source of truth for ALL configuration
 *   • Environment variable management with validation
 *   • System constants and thresholds
 *   • GitHub Actions coordination parameters
 * 
 * 🔒 Security:
 *   • Secrets never logged or exposed
 *   • All sensitive values via getenv() only
 *   • Validation prevents misconfiguration
 *   • Immutable after initialization
 * 
 * @package     KhashayarDownloader
 * @version     5.0.0
 * @author      Khashayar
 * @license     MIT
 * @since       2026-05-16
 */

declare(strict_types=1);

/**
 * Prevent direct access - must be included from another script
 * This ensures the config is only loaded in proper context
 */
if (!defined('APP_RUNNING')) {
    die('⛔ Configuration cannot be loaded directly.');
}

/**
 * ============================================================
 * CRITICAL: Environment Variables
 * ============================================================
 * 
 * ⚠️ MUST BE SET in cPanel or .env file before running
 * 
 * Required:
 *   BALE_BOT_TOKEN    - Telegram Bot API token from @BotFather
 *   GH_PAT            - GitHub Personal Access Token (repo + workflow scopes)
 *   GITHUB_OWNER      - GitHub username/org that owns the repository
 *   GITHUB_REPO       - Repository name (e.g., "youtube-downloader-template")
 * 
 * Optional:
 *   CHANNEL_ID        - Bale channel ID for file archiving
 *   APP_ENV           - 'production' or 'development' (default: production)
 *   LOG_LEVEL         - 'DEBUG', 'INFO', 'WARNING', 'ERROR' (default: INFO)
 */

// ──── Load .env file if exists (for local development) ────
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (!empty($key)) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }
}

// ──── Required Configuration ────
define('BALE_BOT_TOKEN', getenv('BALE_BOT_TOKEN') ?: '');
define('GH_PAT', getenv('GH_PAT') ?: '');
define('GITHUB_OWNER', getenv('GITHUB_OWNER') ?: '');
define('GITHUB_REPO', getenv('GITHUB_REPO') ?: '');

// ──── Optional Configuration ────
define('CHANNEL_ID', getenv('CHANNEL_ID') ?: '');
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('LOG_LEVEL', getenv('LOG_LEVEL') ?: 'INFO');

/**
 * ============================================================
 * Bale API Configuration
 * ============================================================
 */

/** @var string Base URL for Bale Bot API */
define('BALE_API_BASE', 'https://tapi.bale.ai/bot' . BALE_BOT_TOKEN);

/** @var int HTTP timeout for Bale API calls (seconds) */
define('BALE_API_TIMEOUT', 10);

/** @var int Maximum retries for failed Bale API calls */
define('BALE_API_MAX_RETRIES', 2);

/**
 * ============================================================
 * GitHub Configuration
 * ============================================================
 */

/** @var string GitHub API base URL for repository */
define('GITHUB_API_BASE', 'https://api.github.com/repos/' . GITHUB_OWNER . '/' . GITHUB_REPO);

/** @var string Branch/ref for workflow dispatch */
define('GITHUB_REF', 'main');

/** @var string Workflow filename for download dispatches */
define('WORKFLOW_DOWNLOAD', 'yt-dl.yml');

/** @var string Workflow filename for search dispatches */
define('WORKFLOW_SEARCH', 'yt-search.yml');

/** @var int Maximum concurrent GitHub Actions jobs (20 max, 15 safe) */
define('MAX_CONCURRENT_GITHUB_JOBS', 15);

/** @var int GitHub API hourly rate limit (1000 max, 900 safe margin) */
define('GITHUB_API_HOURLY_LIMIT', 900);

/** @var int HTTP timeout for GitHub API calls (seconds) */
define('GITHUB_API_TIMEOUT', 15);

/** @var int Maximum retries for failed GitHub API calls */
define('GITHUB_API_MAX_RETRIES', 3);

/** @var string Custom User-Agent for GitHub API requests */
define('GITHUB_USER_AGENT', 'Khashayar-YouTube-Downloader/5.0');

/**
 * ============================================================
 * Queue System Configuration
 * ============================================================
 */

/** @var int How many jobs to process per cron execution */
define('QUEUE_BATCH_SIZE', 10);

/** @var int Seconds between cron executions (for wait time calculation) */
define('QUEUE_PROCESS_INTERVAL', 15);

/** @var int Minimum seconds between GitHub dispatches (avoids rate limit) */
define('QUEUE_DISPATCH_DELAY', 2);

/** @var int Maximum jobs a single user can have pending simultaneously */
define('QUEUE_MAX_PER_USER', 3);

/** @var int After how many seconds a pending job is considered stale */
define('QUEUE_JOB_TIMEOUT', 1800); // 30 minutes

/** @var int Maximum queue size before warning (for monitoring) */
define('QUEUE_WARNING_SIZE', 500);

/** @var int Maximum queue size before rejecting new jobs */
define('QUEUE_MAX_SIZE', 2000);

/**
 * ============================================================
 * Rate Limiting Configuration
 * ============================================================
 */

/** @var int Seconds between user requests */
define('RATE_LIMIT_SECONDS', 300); // 5 minutes

/** @var int Seconds between status check requests */
define('STATUS_CHECK_SECONDS', 180); // 3 minutes

/** @var int Maximum requests per user per day */
define('DAILY_LIMIT_PER_USER', 50);

/**
 * ============================================================
 * Database Configuration
 * ============================================================
 */

/** @var string Path to SQLite database file */
define('DB_PATH', __DIR__ . '/../data/queue.db');

/** @var string SQLite journal mode (WAL for concurrent access) */
define('DB_JOURNAL_MODE', 'WAL');

/** @var int SQLite busy timeout (milliseconds) */
define('DB_BUSY_TIMEOUT', 5000);

/** @var string Directory for database backups */
define('DB_BACKUP_DIR', __DIR__ . '/../data/backups');

/** @var int Number of days to keep database backups */
define('DB_BACKUP_RETENTION_DAYS', 7);

/**
 * ============================================================
 * Logging Configuration
 * ============================================================
 */

/** @var string Directory for log files */
define('LOG_DIR', __DIR__ . '/../logs');

/** @var string Main queue log file */
define('LOG_QUEUE', LOG_DIR . '/queue.log');

/** @var string Error log file */
define('LOG_ERRORS', LOG_DIR . '/errors.log');

/** @var string Debug log file (only in development) */
define('LOG_DEBUG', LOG_DIR . '/debug.log');

/** @var int Maximum log file size before rotation (bytes) */
define('LOG_MAX_SIZE', 10 * 1024 * 1024); // 10 MB

/** @var int Number of rotated log files to keep */
define('LOG_ROTATION_COUNT', 5);

/**
 * ============================================================
 * Content & Download Configuration
 * ============================================================
 */

/** @var string Project name for branding */
define('PROJECT_NAME', 'Khashayar YouTube Downloader');

/** @var string Project URL */
define('PROJECT_URL', 'https://khashayar.one');

/** @var int File split size for large files (MB) */
define('SPLIT_SIZE_MB', 45);

/** @var int Max file size for direct send via Bale (bytes) - 50MB limit */
define('BALE_MAX_FILE_SIZE', 50 * 1024 * 1024);

/** @var int How long files remain in repository (minutes) */
define('FILE_RETENTION_MINUTES', 5);

/**
 * ============================================================
 * System Paths
 * ============================================================
 */

/** @var string Root directory of the application */
define('APP_ROOT', realpath(__DIR__ . '/..'));

/** @var string Library directory */
define('LIB_DIR', APP_ROOT . '/lib');

/** @var string Data directory */
define('DATA_DIR', APP_ROOT . '/data');

/** @var string Current script filename (for webhook URL) */
define('SELF_FILENAME', basename($_SERVER['SCRIPT_NAME'] ?? 'gateway.php'));

/**
 * ============================================================
 * Quality Mapping
 * ============================================================
 */

/** @var array<string, string> Mapping of quality codes to display names */
define('QUALITY_MAP', [
    'best'   => '✨ Best Quality',
    '2160'   => '4K (2160p)',
    '1440'   => '2K (1440p)',
    '1080'   => 'Full HD (1080p)',
    '720'    => 'HD (720p)',
    '480'    => 'SD (480p)',
    'audio'  => '🎵 Audio Only (MP3)',
]);

/** @var array<string> Valid quality options */
define('VALID_QUALITIES', array_keys(QUALITY_MAP));

/**
 * ============================================================
 * Initialization & Validation
 * ============================================================
 */

/**
 * Validate critical configuration on load
 * Dies with clear error message if required config is missing
 * 
 * @return void
 * @throws RuntimeException if required environment variables are missing
 */
function validateConfig(): void
{
    $errors = [];
    
    if (empty(BALE_BOT_TOKEN)) {
        $errors[] = 'BALE_BOT_TOKEN is not set. Add it to environment variables.';
    }
    
    if (empty(GH_PAT)) {
        $errors[] = 'GH_PAT is not set. Add your GitHub Personal Access Token.';
    }
    
    if (empty(GITHUB_OWNER)) {
        $errors[] = 'GITHUB_OWNER is not set. Specify your GitHub username.';
    }
    
    if (empty(GITHUB_REPO)) {
        $errors[] = 'GITHUB_REPO is not set. Specify your repository name.';
    }
    
    if (!empty($errors)) {
        $message = "⛔ Configuration Error:\n\n" . implode("\n", $errors);
        $message .= "\n\n📋 Set these in cPanel:";
        $message .= "\n   Settings > Secrets and variables > Environment Variables";
        $message .= "\n\nOr create a .env file in the application root.";
        
        if (APP_ENV === 'development') {
            die($message);
        } else {
            error_log('[Config] Critical configuration missing: ' . implode(', ', $errors));
            http_response_code(500);
            die('Internal server error. Check server configuration.');
        }
    }
    
    // Create required directories if they don't exist
    $directories = [LOG_DIR, DATA_DIR, DB_BACKUP_DIR];
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
                error_log("[Config] Failed to create directory: {$dir}");
            }
        }
    }
    
    // Verify directories are writable
    foreach ([LOG_DIR, DATA_DIR] as $dir) {
        if (!is_writable($dir)) {
            error_log("[Config] Directory not writable: {$dir}");
        }
    }
}

/**
 * Get the current webhook URL based on server configuration
 * 
 * @return string Full URL to gateway.php
 */
function getWebhookUrl(): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = str_replace(SELF_FILENAME, '', $_SERVER['SCRIPT_NAME'] ?? '/');
    
    return $protocol . $host . $path . SELF_FILENAME;
}

/**
 * Check if running in development mode
 * 
 * @return bool True if APP_ENV is 'development'
 */
function isDevelopment(): bool
{
    return APP_ENV === 'development';
}

/**
 * Check if running in production mode
 * 
 * @return bool True if APP_ENV is 'production'
 */
function isProduction(): bool
{
    return APP_ENV === 'production';
}

/**
 * Get a human-readable queue status message
 * 
 * @param int $position User's position in queue
 * @return string Formatted status message
 */
function getQueueStatusMessage(int $position): string
{
    $waitTime = ceil(($position / QUEUE_BATCH_SIZE) * QUEUE_PROCESS_INTERVAL);
    
    if ($waitTime < 60) {
        $waitStr = "کمتر از ۱ دقیقه";
    } elseif ($waitTime < 120) {
        $waitStr = "حدود ۱ دقیقه";
    } else {
        $minutes = ceil($waitTime / 60);
        $waitStr = "حدود {$minutes} دقیقه";
    }
    
    return "⏳ *شما در صف هستید!*\n\n🔢 موقعیت: {$position}\n⏱ زمان تقریبی: {$waitStr}\n\n📊 وضعیت خودکار بروزرسانی می‌شود.";
}

// ──── Run validation on load ────
if (!defined('SKIP_CONFIG_VALIDATION')) {
    validateConfig();
}

// ──── Initialization complete ────
define('CONFIG_LOADED', true);