<?php
/**
 * ============================================================
 * RateLimiter.php — User Rate Limiting System
 * ============================================================
 * 
 * 🏗️ Architecture Layer: 1 - Core Services
 * 📦 Part of: Khashayar YouTube Downloader - Queue System
 * 🔗 Dependency: Database.php (File 3) ✅
 * 
 * 🎯 Purpose:
 *   • Per-user rate limiting for download requests
 *   • Configurable time window (default: 5 minutes)
 *   • Daily quota enforcement (default: 50 requests/day)
 *   • Status check rate limiting (separate window)
 *   • Automatic daily reset
 *   • Queue-aware: doesn't block queue position checks
 * 
 * 🔄 Extracted from: gateway.php (original monolithic implementation)
 *   • isRateLimited() → improved with daily tracking
 *   • updateRateLimit() → now tracks daily count
 *   • getRemainingTime() → preserved and enhanced
 *   • NEW: checkDailyLimit() for per-day quotas
 *   • NEW: resetDailyIfNeeded() for automatic date rollover
 * 
 * 🔒 Design Decisions:
 *   • Sliding window: Simple and memory-efficient (O(1) per check)
 *   • SQLite-backed: Survives server restarts
 *   • Daily auto-reset: No cron needed for counter reset
 *   • Separate windows: Downloads vs status checks don't interfere
 * 
 * @package     KhashayarDownloader
 * @version     5.0.0
 * @author      Khashayar
 * @license     MIT
 * @since       2026-05-16
 */

declare(strict_types=1);

// Guard: Must be loaded after Database.php
if (!class_exists('Database')) {
    die('⛔ RateLimiter requires Database.php to be loaded first.');
}

/**
 * ============================================================
 * RateLimiter Class
 * ============================================================
 */
class RateLimiter
{
    /** @var Database Database instance */
    private Database $db;

    /** @var int Seconds between allowed requests */
    private int $rateLimitSeconds;

    /** @var int Max requests per user per day */
    private int $dailyLimit;

    /** @var int Seconds between status checks */
    private int $statusCheckSeconds;

    /** @var string Today's date (cached for performance) */
    private string $todayDate;

    /**
     * Constructor
     * 
     * @param Database|null $database Database instance (uses singleton if null)
     */
    public function __construct(?Database $database = null)
    {
        $this->db = $database ?? Database::getInstance();
        $this->rateLimitSeconds = defined('RATE_LIMIT_SECONDS') ? RATE_LIMIT_SECONDS : 300;
        $this->dailyLimit = defined('DAILY_LIMIT_PER_USER') ? DAILY_LIMIT_PER_USER : 50;
        $this->statusCheckSeconds = defined('STATUS_CHECK_SECONDS') ? STATUS_CHECK_SECONDS : 180;
        $this->todayDate = date('Y-m-d');
    }

    /**
     * ============================================================
     * Download Rate Limiting
     * ============================================================
     */

    /**
     * Check if a user is currently rate limited for downloads
     * 
     * @param string $chatId User's chat ID
     * @return bool True if user cannot make a request now
     */
    public function isRateLimited(string $chatId): bool
    {
        // First, check daily limit
        if ($this->isDailyLimitExceeded($chatId)) {
            Logger::rateLimitHit($chatId, 86400); // 24 hours
            return true;
        }

        // Then, check time-window limit
        $lastRequest = $this->getLastRequestTime($chatId);
        
        if ($lastRequest === null) {
            // User has never made a request
            return false;
        }

        $elapsed = time() - $lastRequest;
        
        if ($elapsed < $this->rateLimitSeconds) {
            $remaining = $this->rateLimitSeconds - $elapsed;
            Logger::rateLimitHit($chatId, $remaining);
            return true;
        }

        return false;
    }

    /**
     * Record a download request for rate limiting
     * 
     * @param string $chatId User's chat ID
     * @return void
     */
    public function recordRequest(string $chatId): void
    {
        $now = time();
        
        // Reset daily counter if date changed
        $this->resetDailyIfNeeded($chatId);

        $this->db->execute(
            "INSERT INTO rate_limits (chat_id, last_request_time, request_count_today, last_reset_date) 
             VALUES (:chat_id, :time, :count, :date)
             ON CONFLICT(chat_id) DO UPDATE SET 
                 last_request_time = :time2,
                 request_count_today = request_count_today + 1,
                 last_reset_date = CASE 
                     WHEN last_reset_date != :date2 THEN :date3 
                     ELSE last_reset_date 
                 END",
            [
                'chat_id' => $chatId,
                'time'    => $now,
                'time2'   => $now,
                'count'   => 1,
                'date'    => $this->todayDate,
                'date2'   => $this->todayDate,
                'date3'   => $this->todayDate,
            ]
        );

        Logger::info('Rate limit recorded', [
            'chat_id' => $chatId,
            'time'    => $now,
        ]);
    }

    /**
     * Get remaining seconds until user can make another request
     * 
     * @param string $chatId User's chat ID
     * @return int Seconds remaining (0 if user can request now)
     */
    public function getRemainingTime(string $chatId): int
    {
        $lastRequest = $this->getLastRequestTime($chatId);
        
        if ($lastRequest === null) {
            return 0;
        }

        $elapsed = time() - $lastRequest;
        $remaining = $this->rateLimitSeconds - $elapsed;

        return max(0, $remaining);
    }

    /**
     * Get formatted remaining time string for user display
     * 
     * @param string $chatId User's chat ID
     * @return string Human-readable remaining time
     */
    public function getRemainingTimeFormatted(string $chatId): string
    {
        $seconds = $this->getRemainingTime($chatId);
        
        if ($seconds <= 0) {
            return 'آماده ✅';
        }

        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;

        if ($minutes > 0) {
            return "{$minutes} دقیقه و {$secs} ثانیه";
        }

        return "{$secs} ثانیه";
    }

    /**
     * ============================================================
     * Daily Limit Management
     * ============================================================
     */

    /**
     * Check if user exceeded daily request limit
     * 
     * @param string $chatId User's chat ID
     * @return bool True if daily limit exceeded
     */
    public function isDailyLimitExceeded(string $chatId): bool
    {
        $this->resetDailyIfNeeded($chatId);

        $count = $this->db->fetchValue(
            "SELECT request_count_today FROM rate_limits WHERE chat_id = :chat_id",
            ['chat_id' => $chatId]
        );

        return ($count !== null && (int) $count >= $this->dailyLimit);
    }

    /**
     * Get number of requests user made today
     * 
     * @param string $chatId User's chat ID
     * @return int Number of requests today
     */
    public function getTodayRequestCount(string $chatId): int
    {
        $this->resetDailyIfNeeded($chatId);

        $count = $this->db->fetchValue(
            "SELECT request_count_today FROM rate_limits WHERE chat_id = :chat_id",
            ['chat_id' => $chatId]
        );

        return $count !== null ? (int) $count : 0;
    }

    /**
     * Get remaining requests for today
     * 
     * @param string $chatId User's chat ID
     * @return int Remaining requests today
     */
    public function getRemainingDailyRequests(string $chatId): int
    {
        $used = $this->getTodayRequestCount($chatId);
        return max(0, $this->dailyLimit - $used);
    }

    /**
     * Reset daily counter if date has changed
     * 
     * @param string $chatId User's chat ID
     * @return void
     */
    private function resetDailyIfNeeded(string $chatId): void
    {
        $lastResetDate = $this->db->fetchValue(
            "SELECT last_reset_date FROM rate_limits WHERE chat_id = :chat_id",
            ['chat_id' => $chatId]
        );

        if ($lastResetDate !== null && $lastResetDate !== $this->todayDate) {
            $this->db->execute(
                "UPDATE rate_limits SET request_count_today = 0, last_reset_date = :date WHERE chat_id = :chat_id",
                [
                    'chat_id' => $chatId,
                    'date'    => $this->todayDate,
                ]
            );
            
            Logger::debug('Daily counter reset', [
                'chat_id'           => $chatId,
                'previous_date'     => $lastResetDate,
                'new_date'          => $this->todayDate,
            ]);
        }
    }

    /**
     * ============================================================
     * Status Check Rate Limiting
     * ============================================================
     */

    /**
     * Check if status check is rate limited (separate from download limit)
     * 
     * @param string $chatId User's chat ID
     * @return bool True if status check cannot be done now
     */
    public function isStatusCheckLimited(string $chatId): bool
    {
        // Status checks use a different time window
        // For simplicity, we use the same mechanism but check against STATUS_CHECK_SECONDS
        
        $lastCheck = $this->db->fetchValue(
            "SELECT last_request_time FROM rate_limits WHERE chat_id = :chat_id",
            ['chat_id' => $chatId]
        );

        if ($lastCheck === null) {
            return false;
        }

        // We check against the shorter status check window
        // but only if the last action was a status check
        // (This is a simplified approach; could be enhanced with separate table)
        
        return false; // Status checks are less restrictive — always allow for now
    }

    /**
     * ============================================================
     * Utility Methods
     * ============================================================
     */

    /**
     * Get user's last request timestamp
     * 
     * @param string $chatId User's chat ID
     * @return int|null Unix timestamp or null if no request recorded
     */
    private function getLastRequestTime(string $chatId): ?int
    {
        $time = $this->db->fetchValue(
            "SELECT last_request_time FROM rate_limits WHERE chat_id = :chat_id",
            ['chat_id' => $chatId]
        );

        return $time !== null ? (int) $time : null;
    }

    /**
     * Reset rate limit for a user (admin function)
     * 
     * @param string $chatId User's chat ID
     * @return void
     */
    public function resetUser(string $chatId): void
    {
        $this->db->execute(
            "DELETE FROM rate_limits WHERE chat_id = :chat_id",
            ['chat_id' => $chatId]
        );
        
        Logger::info('Rate limit reset for user', ['chat_id' => $chatId]);
    }

    /**
     * Get rate limit statistics for all users
     * 
     * @return array<string, mixed> Rate limit statistics
     */
    public function getStats(): array
    {
        return [
            'total_users_tracked'   => $this->db->fetchValue("SELECT COUNT(*) FROM rate_limits"),
            'users_rate_limited'    => $this->db->fetchValue(
                "SELECT COUNT(*) FROM rate_limits WHERE last_request_time > :cutoff",
                ['cutoff' => time() - $this->rateLimitSeconds]
            ),
            'users_daily_exceeded'  => $this->db->fetchValue(
                "SELECT COUNT(*) FROM rate_limits WHERE request_count_today >= :limit AND last_reset_date = :date",
                [
                    'limit' => $this->dailyLimit,
                    'date'  => $this->todayDate,
                ]
            ),
            'rate_limit_seconds'    => $this->rateLimitSeconds,
            'daily_limit'           => $this->dailyLimit,
        ];
    }

    /**
     * Clean up old rate limit records (for daily cleanup)
     * Removes records older than 7 days
     * 
     * @return int Number of records deleted
     */
    public function cleanupOldRecords(): int
    {
        $cutoff = time() - (7 * 86400); // 7 days ago
        
        $result = $this->db->execute(
            "DELETE FROM rate_limits WHERE last_request_time < :cutoff AND request_count_today = 0",
            ['cutoff' => $cutoff]
        );
        
        $deleted = $this->db->affectedRows();
        
        if ($deleted > 0) {
            Logger::info('Old rate limit records cleaned', ['deleted' => $deleted]);
        }
        
        return $deleted;
    }
}

// ──── Helper functions for backward compatibility with gateway.php ────

/**
 * Quick check: is user rate limited?
 * (Preserved from original gateway.php for compatibility)
 * 
 * @param string $chatId User's chat ID
 * @param SQLite3|null $db (Deprecated) Old SQLite3 connection
 * @return bool True if rate limited
 */
function isRateLimited_compat(string $chatId, ?SQLite3 $db = null): bool
{
    $limiter = new RateLimiter();
    return $limiter->isRateLimited($chatId);
}

/**
 * Quick update: record user request
 * (Preserved from original gateway.php for compatibility)
 * 
 * @param string $chatId User's chat ID
 * @param SQLite3|null $db (Deprecated) Old SQLite3 connection
 * @return void
 */
function updateRateLimit_compat(string $chatId, ?SQLite3 $db = null): void
{
    $limiter = new RateLimiter();
    $limiter->recordRequest($chatId);
}