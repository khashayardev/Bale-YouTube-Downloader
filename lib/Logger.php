<?php
/**
 * ============================================================
 * Logger.php — Centralized Logging System
 * ============================================================
 * 
 * 🏗️ Architecture Layer: 0 - Fundamentals
 * 📦 Part of: Khashayar YouTube Downloader - Queue System
 * 🔗 Dependency: Config.php (File 1) ✅
 * 
 * 🎯 Purpose:
 *   • Structured logging with severity levels
 *   • Automatic log rotation (prevents disk exhaustion)
 *   • Separate log files for queue, errors, and debug
 *   • Sensitive data sanitization (never log tokens/credentials)
 *   • Minimal performance overhead in production
 *   • Contextual logging with job IDs and chat IDs
 * 
 * 🔒 Security:
 *   • Automatically redacts BOT_TOKEN and GH_PAT from logs
 *   • Chat IDs hashed in production logs
 *   • Debug logs only enabled in development
 *   • Log files protected by directory permissions (0750)
 * 
 * 📊 Log Levels (PSR-3 inspired):
 *   DEBUG   - Detailed debug information (development only)
 *   INFO    - Normal operational events (queue processing, downloads)
 *   WARNING - Unexpected but non-critical events (rate limits, retries)
 *   ERROR   - Critical errors requiring attention (API failures)
 * 
 * @package     KhashayarDownloader
 * @version     5.0.0
 * @author      Khashayar
 * @license     MIT
 * @since       2026-05-16
 */

declare(strict_types=1);

// Guard: This file must be loaded after Config.php
if (!defined('CONFIG_LOADED')) {
    die('⛔ Logger requires Config.php to be loaded first.');
}

/**
 * ============================================================
 * Logger Class — Main Logging Interface
 * ============================================================
 * 
 * Usage:
 *   Logger::info('Queue processed', ['batch_size' => 10, 'jobs' => 8]);
 *   Logger::warning('Rate limit approaching', ['remaining' => 50]);
 *   Logger::error('GitHub API failed', ['http_code' => 429, 'retry' => 3]);
 *   Logger::debug('SQL query executed', ['sql' => 'SELECT ...', 'time' => '2ms']);
 */
class Logger
{
    /** @var array<int, string> Log level hierarchy */
    private const LEVELS = [
        0 => 'DEBUG',
        1 => 'INFO',
        2 => 'WARNING',
        3 => 'ERROR',
    ];

    /** @var array<string, int> String to integer level mapping */
    private const LEVEL_MAP = [
        'DEBUG'   => 0,
        'INFO'    => 1,
        'WARNING' => 2,
        'ERROR'   => 3,
    ];

    /** @var int Current minimum log level */
    private static int $minLevel;

    /** @var bool Whether to write debug logs */
    private static bool $debugEnabled;

    /** @var array<string> Sensitive patterns to redact from logs */
    private static array $sensitivePatterns = [];

    /** @var bool Initialization flag */
    private static bool $initialized = false;

    /**
     * Initialize the Logger
     * Called automatically on first use
     * 
     * @return void
     */
    private static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        // Set minimum log level based on configuration
        $configuredLevel = defined('LOG_LEVEL') ? strtoupper(LOG_LEVEL) : 'INFO';
        self::$minLevel = self::LEVEL_MAP[$configuredLevel] ?? 1;
        
        // Debug logs only in development mode
        self::$debugEnabled = isDevelopment();

        // Register sensitive patterns for redaction
        if (defined('BALE_BOT_TOKEN') && !empty(BALE_BOT_TOKEN)) {
            self::$sensitivePatterns[] = BALE_BOT_TOKEN;
        }
        if (defined('GH_PAT') && !empty(GH_PAT)) {
            self::$sensitivePatterns[] = GH_PAT;
        }

        // Ensure log directory exists and is writable
        if (!is_dir(LOG_DIR)) {
            @mkdir(LOG_DIR, 0750, true);
        }

        self::$initialized = true;
    }

    /**
     * Log a DEBUG message
     * Only written in development mode
     * 
     * @param string $message Log message
     * @param array<string, mixed> $context Additional context data
     * @return void
     */
    public static function debug(string $message, array $context = []): void
    {
        if (!self::$debugEnabled) {
            return;
        }
        self::log('DEBUG', $message, $context, LOG_DEBUG);
    }

    /**
     * Log an INFO message
     * Normal operational events
     * 
     * @param string $message Log message
     * @param array<string, mixed> $context Additional context data
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context, LOG_QUEUE);
    }

    /**
     * Log a WARNING message
     * Non-critical but unexpected events
     * 
     * @param string $message Log message
     * @param array<string, mixed> $context Additional context data
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context, LOG_QUEUE);
    }

    /**
     * Log an ERROR message
     * Critical errors requiring attention
     * Also written to error log file
     * 
     * @param string $message Log message
     * @param array<string, mixed> $context Additional context data
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context, LOG_ERRORS);
        // Also write to main queue log for visibility
        self::log('ERROR', $message, $context, LOG_QUEUE);
    }

    /**
     * Core logging method
     * Formats, sanitizes, and writes log entries
     * 
     * @param string $level Log level (DEBUG, INFO, WARNING, ERROR)
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     * @param string $logFile Target log file path
     * @return void
     */
    private static function log(string $level, string $message, array $context, string $logFile): void
    {
        self::init();

        // Check if this level should be logged
        $levelInt = self::LEVEL_MAP[$level] ?? 1;
        if ($levelInt < self::$minLevel && $level !== 'ERROR') {
            return;
        }

        try {
            // Build log entry
            $entry = self::formatEntry($level, $message, $context);
            
            // Rotate log if needed
            self::rotateIfNeeded($logFile);
            
            // Write to file
            $result = @file_put_contents(
                $logFile,
                $entry . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
            
            // Fallback to system error log if file write fails
            if ($result === false) {
                error_log("[Logger] Failed to write to {$logFile}: {$message}");
            }
            
        } catch (\Throwable $e) {
            // Last resort: system error log
            error_log("[Logger] Critical failure: {$e->getMessage()}");
        }
    }

    /**
     * Format a log entry with timestamp, level, message, and context
     * 
     * @param string $level Log level
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     * @return string Formatted log entry
     */
    private static function formatEntry(string $level, string $message, array $context): string
    {
        // Build timestamp with microseconds for precision
        $timestamp = date('Y-m-d H:i:s.v');
        
        // Sanitize message and context
        $message = self::sanitize($message);
        $context = self::sanitizeContext($context);
        
        // Build context string
        $contextStr = '';
        if (!empty($context)) {
            // Filter out empty values and format
            $filtered = array_filter($context, function ($value) {
                return $value !== null && $value !== '' && $value !== [];
            });
            
            if (!empty($filtered)) {
                $contextStr = ' | ' . json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        
        // Format: [2026-05-16 14:30:45.123] INFO - Queue processed | {"batch":10,"jobs":8}
        return "[{$timestamp}] {$level} - {$message}{$contextStr}";
    }

    /**
     * Sanitize a string to remove sensitive information
     * 
     * @param string $text Text to sanitize
     * @return string Sanitized text
     */
    private static function sanitize(string $text): string
    {
        foreach (self::$sensitivePatterns as $pattern) {
            if (empty($pattern)) {
                continue;
            }
            
            // Replace token with redacted version
            $text = str_replace($pattern, '[REDACTED]', $text);
            
            // Also redact partial matches (for truncated tokens)
            if (strlen($pattern) > 20) {
                $shortPattern = substr($pattern, 0, 20);
                if (str_contains($text, $shortPattern)) {
                    $text = str_replace($shortPattern, '[REDACTED_PARTIAL]', $text);
                }
            }
        }
        
        return $text;
    }

    /**
     * Sanitize context array to protect sensitive data
     * 
     * @param array<string, mixed> $context Context data
     * @return array<string, mixed> Sanitized context
     */
    private static function sanitizeContext(array $context): array
    {
        $sanitized = [];
        
        foreach ($context as $key => $value) {
            // Skip null values
            if ($value === null) {
                continue;
            }
            
            // Hash chat_id in production for privacy
            if ($key === 'chat_id' && isProduction()) {
                $sanitized[$key] = substr(hash('sha256', (string) $value), 0, 12) . '...';
                continue;
            }
            
            // Truncate long strings
            if (is_string($value) && strlen($value) > 500) {
                $sanitized[$key] = substr($value, 0, 500) . '... [truncated]';
                continue;
            }
            
            // Recursively sanitize arrays
            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeContext($value);
                continue;
            }
            
            // Sanitize string values
            if (is_string($value)) {
                $sanitized[$key] = self::sanitize($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }

    /**
     * Rotate log file if it exceeds maximum size
     * 
     * @param string $logFile Path to log file
     * @return void
     */
    private static function rotateIfNeeded(string $logFile): void
    {
        // Check if file exists and exceeds max size
        if (!file_exists($logFile)) {
            return;
        }
        
        $size = @filesize($logFile);
        if ($size === false || $size < LOG_MAX_SIZE) {
            return;
        }
        
        try {
            // Rotate existing backup files (log.1 -> log.2, etc.)
            for ($i = LOG_ROTATION_COUNT - 1; $i >= 1; $i--) {
                $oldFile = "{$logFile}.{$i}";
                $newFile = "{$logFile}." . ($i + 1);
                if (file_exists($oldFile)) {
                    @rename($oldFile, $newFile);
                }
            }
            
            // Move current log to .1
            @rename($logFile, "{$logFile}.1");
            
            // Create fresh log file
            @touch($logFile);
            @chmod($logFile, 0640);
            
        } catch (\Throwable $e) {
            error_log("[Logger] Log rotation failed: {$e->getMessage()}");
        }
    }

    /**
     * Log an exception with full stack trace
     * 
     * @param \Throwable $exception The exception to log
     * @param string $context Description of what was happening
     * @return void
     */
    public static function exception(\Throwable $exception, string $context = ''): void
    {
        $message = $context ? "{$context}: {$exception->getMessage()}" : $exception->getMessage();
        
        self::error($message, [
            'exception' => get_class($exception),
            'file'      => basename($exception->getFile()),
            'line'      => $exception->getLine(),
            'code'      => $exception->getCode(),
            'trace'     => substr($exception->getTraceAsString(), 0, 1000),
        ]);
    }

    /**
     * Log queue processing statistics
     * 
     * @param int $processed Number of jobs processed
     * @param int $pending Number of jobs remaining in queue
     * @param float $duration Processing duration in seconds
     * @return void
     */
    public static function queueStats(int $processed, int $pending, float $duration): void
    {
        self::info('Queue batch processed', [
            'processed'  => $processed,
            'pending'    => $pending,
            'duration_ms' => round($duration * 1000, 2),
            'rate_per_min' => $duration > 0 ? round(($processed / $duration) * 60, 1) : 0,
        ]);
    }

    /**
     * Log a rate limit event
     * 
     * @param string $chatId Chat ID that hit rate limit
     * @param int $remainingSeconds Seconds remaining
     * @return void
     */
    public static function rateLimitHit(string $chatId, int $remainingSeconds): void
    {
        self::info('Rate limit enforced', [
            'chat_id'           => $chatId,
            'remaining_seconds'  => $remainingSeconds,
        ]);
    }

    /**
     * Log a GitHub API interaction
     * 
     * @param string $action What action was performed
     * @param bool $success Whether it succeeded
     * @param int $httpCode HTTP response code
     * @param int $rateLimitRemaining Remaining API calls
     * @return void
     */
    public static function githubApi(string $action, bool $success, int $httpCode, int $rateLimitRemaining = -1): void
    {
        $level = $success ? 'INFO' : 'WARNING';
        
        $context = [
            'action'     => $action,
            'http_code'  => $httpCode,
        ];
        
        if ($rateLimitRemaining >= 0) {
            $context['rate_limit_remaining'] = $rateLimitRemaining;
        }
        
        self::log($level, 'GitHub API call', $context, LOG_QUEUE);
    }

    /**
     * Get the current log file contents (for monitoring)
     * 
     * @param string $logFile Log file to read
     * @param int $lines Number of last lines to return
     * @return array<int, string> Array of log lines
     */
    public static function tail(string $logFile = LOG_QUEUE, int $lines = 100): array
    {
        if (!file_exists($logFile)) {
            return ['📄 Log file is empty or does not exist.'];
        }
        
        $content = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($content === false) {
            return ['⚠️ Cannot read log file.'];
        }
        
        return array_slice($content, -$lines);
    }

    /**
     * Clear all log files (for manual maintenance)
     * 
     * @return array<string, bool> Results per log file
     */
    public static function clearAll(): array
    {
        $results = [];
        $logFiles = [LOG_QUEUE, LOG_ERRORS, LOG_DEBUG];
        
        foreach ($logFiles as $logFile) {
            if (file_exists($logFile)) {
                $results[basename($logFile)] = @unlink($logFile);
            }
        }
        
        return $results;
    }
}

// ──── Helper function for quick logging (shorthand) ────

/**
 * Quick log function for simple messages
 * 
 * @param string $message Message to log at INFO level
 * @return void
 */
function log_info(string $message): void
{
    Logger::info($message);
}

/**
 * Quick log function for error messages
 * 
 * @param string $message Message to log at ERROR level
 * @return void
 */
function log_error(string $message): void
{
    Logger::error($message);
}
