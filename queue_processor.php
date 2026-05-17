<?php
/**
 * ============================================================
 * queue_processor.php — Cron Queue Processor
 * ============================================================
 * 
 * 🏗️ Architecture Layer: 3 - Entry Point (Cron Job)
 * 📦 Part of: Khashayar YouTube Downloader - Queue System
 * 🔗 Dependency: QueueManager (File 7) + all underlying layers
 * 
 * 🎯 Purpose:
 *   • Cron-invoked script to process pending queue jobs
 *   • Runs every 15-30 seconds via cPanel cron
 *   • Acquires lock to prevent parallel execution
 *   • Checks GitHub capacity before dispatching
 *   • Processes up to QUEUE_BATCH_SIZE jobs per run
 *   • Logs detailed statistics for monitoring
 *   • Self-healing: auto-recovers from errors
 * 
 * ⚙️ Cron Setup (cPanel):
 *   * * * * * /usr/bin/php /home/user/public_html/queue_processor.php
 *   * * * * * sleep 15; /usr/bin/php /home/user/public_html/queue_processor.php
 *   * * * * * sleep 30; /usr/bin/php /home/user/public_html/queue_processor.php
 *   * * * * * sleep 45; /usr/bin/php /home/user/public_html/queue_processor.php
 * 
 * 🔒 Security:
 *   • Lock file prevents concurrent execution
 *   • Lock auto-expires after 120 seconds (stale lock protection)
 *   • Can only be run from CLI (not via web)
 *   • All output logged, never exposed to web
 * 
 * @package     KhashayarDownloader
 * @version     5.0.0
 * @author      Khashayar
 * @license     MIT
 * @since       2026-05-16
 */

declare(strict_types=1);

// ══════════════════════════════════════════════════════════
// Security: Ensure CLI-only execution
// ══════════════════════════════════════════════════════════

define('APP_RUNNING', true);

if (php_sapi_name() !== 'cli' && !defined('APP_RUNNING')) {
    http_response_code(403);
    die('⛔ This script can only be run from command line (cron).');
}

// ══════════════════════════════════════════════════════════
// Bootstrap
// ══════════════════════════════════════════════════════════



require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Logger.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/RateLimiter.php';
require_once __DIR__ . '/lib/BaleNotifier.php';
require_once __DIR__ . '/lib/GitHubClient.php';
require_once __DIR__ . '/lib/QueueManager.php';

// ══════════════════════════════════════════════════════════
// Lock Management (prevents parallel cron execution)
// ══════════════════════════════════════════════════════════

/**
 * Acquire process lock
 * Uses both file lock and database lock for reliability
 * 
 * @param Database $db Database instance
 * @return bool True if lock acquired
 */
function acquireLock(Database $db): bool
{
    $lockFile = DATA_DIR . '/queue_processor.lock';
    $pid = getmypid();
    
    // Try database lock first
    if (!$db->acquireLock('queue_processor', 120)) {
        Logger::debug('Queue processor: database lock already held');
        return false;
    }
    
    // Try file lock
    if (file_exists($lockFile)) {
        $lockAge = time() - filemtime($lockFile);
        
        // If lock is older than 120 seconds, it's stale — override
        if ($lockAge > 120) {
            Logger::warning('Stale lock file detected, overriding', ['age_seconds' => $lockAge]);
            @unlink($lockFile);
        } else {
            Logger::debug('Queue processor: file lock already held');
            $db->releaseLock('queue_processor');
            return false;
        }
    }
    
    // Create lock file
    if (@file_put_contents($lockFile, (string) $pid, LOCK_EX) === false) {
        Logger::error('Failed to create lock file');
        $db->releaseLock('queue_processor');
        return false;
    }
    
    return true;
}

/**
 * Release process lock
 * 
 * @param Database $db Database instance
 * @return void
 */
function releaseLock(Database $db): void
{
    $lockFile = DATA_DIR . '/queue_processor.lock';
    
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
    
    $db->releaseLock('queue_processor');
}

// ══════════════════════════════════════════════════════════
// Main Processing
// ══════════════════════════════════════════════════════════

/**
 * Main entry point for queue processing
 * 
 * @return int Exit code (0 = success, 1 = skipped, 2 = error)
 */
function main(): int
{
    $startTime = microtime(true);
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "  Queue Processor v5.0 — " . date('Y-m-d H:i:s') . "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    try {
        // Initialize services
        $db = Database::getInstance();
        $githubClient = new GitHubClient();
        $rateLimiter = new RateLimiter($db);
        $queueManager = new QueueManager($db, $githubClient, $rateLimiter);
        
        // Acquire lock
        if (!acquireLock($db)) {
            echo "⏭️  Another instance is already running. Skipping.\n";
            Logger::debug('Queue processor skipped — lock held by another instance');
            return 1;
        }
        
        echo "🔒 Lock acquired\n";
        
        // ──── Pre-flight Checks ────
        
        // Check GitHub rate limit
        $rateStatus = $githubClient->getRateLimitStatus();
        echo "📊 GitHub API: {$rateStatus['remaining']} remaining";
        if (!$rateStatus['safe']) {
            echo " (⚠️ near limit)";
        }
        echo "\n";
        
        if (!$githubClient->hasRateLimitRemaining()) {
            echo "⏸️  GitHub rate limit too low. Skipping batch.\n";
            Logger::warning('Queue processor: GitHub rate limit too low', $rateStatus);
            releaseLock($db);
            return 1;
        }
        
        // Check concurrent GitHub jobs
        $activeJobs = $githubClient->countActiveJobs();
        echo "🔄 Active GitHub jobs: {$activeJobs}\n";
        
        $maxConcurrent = defined('MAX_CONCURRENT_GITHUB_JOBS') ? MAX_CONCURRENT_GITHUB_JOBS : 15;
        if ($activeJobs >= $maxConcurrent) {
            echo "⏸️  GitHub concurrent job limit reached ({$activeJobs}/{$maxConcurrent}). Skipping.\n";
            Logger::info('Queue processor: GitHub concurrent limit reached', [
                'active' => $activeJobs,
                'max'    => $maxConcurrent,
            ]);
            releaseLock($db);
            return 1;
        }
        
        // ──── Process Queue ────
        
        $queueSize = $queueManager->getQueueSize();
        echo "📋 Pending jobs: {$queueSize}\n\n";
        
        if ($queueSize === 0) {
            echo "✅ Queue is empty. Nothing to process.\n";
            releaseLock($db);
            return 0;
        }
        
        echo "🚀 Processing batch...\n";
        
        $result = $queueManager->processBatch();
        
        // ──── Check Completed Jobs ────
        
        echo "\n🔍 Checking completed jobs...\n";
        $completed = $queueManager->checkCompletedJobs();
        
        // ──── Output Summary ────
        
        $duration = round(microtime(true) - $startTime, 2);
        
        echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "  📊 Batch Summary\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "  ⏱  Duration:        {$duration}s\n";
        echo "  📤 Dispatched:       {$result['dispatched']}\n";
        echo "  ⏭️  Skipped:          {$result['skipped']}\n";
        echo "  ❌ Errors:           {$result['errors']}\n";
        echo "  📋 Remaining:        {$result['pending_remaining']}\n";
        echo "  ✅ Completed (check): {$completed['completed']}\n";
        echo "  ❌ Failed (check):    {$completed['failed']}\n";
        echo "  🔄 Still running:    {$completed['still_running']}\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        // ──── Cleanup ────
        
        releaseLock($db);
        
        // Update daily stats
        updateDailyStats($db, $result, $completed);
        
        Logger::info('Queue processor finished', [
            'duration'      => $duration,
            'dispatched'    => $result['dispatched'],
            'remaining'     => $result['pending_remaining'],
            'completed'     => $completed['completed'],
        ]);
        
        return 0;
        
    } catch (\Exception $e) {
        echo "\n❌ FATAL ERROR: {$e->getMessage()}\n";
        echo "File: {$e->getFile()}:{$e->getLine()}\n";
        
        Logger::exception($e, 'Queue processor fatal error');
        
        // Try to release lock
        try {
            if (isset($db)) {
                releaseLock($db);
            }
        } catch (\Exception $releaseError) {
            // Last resort
        }
        
        return 2;
    }
}

/**
 * Update daily statistics in database
 * 
 * @param Database $db Database instance
 * @param array $result Batch processing result
 * @param array $completed Completed jobs result
 * @return void
 */
function updateDailyStats(Database $db, array $result, array $completed): void
{
    try {
        $today = date('Y-m-d');
        
        $db->execute(
            "INSERT INTO queue_stats_daily (date, total_requests, completed_requests, failed_requests, peak_queue_length, updated_at)
             VALUES (:date, :total, :completed, :failed, :peak, :time)
             ON CONFLICT(date) DO UPDATE SET
                 total_requests = total_requests + :total2,
                 completed_requests = completed_requests + :completed2,
                 failed_requests = failed_requests + :failed2,
                 peak_queue_length = MAX(peak_queue_length, :peak2),
                 updated_at = :time2",
            [
                'date'       => $today,
                'total'      => $result['dispatched'],
                'total2'     => $result['dispatched'],
                'completed'  => $completed['completed'],
                'completed2' => $completed['completed'],
                'failed'     => $completed['failed'],
                'failed2'    => $completed['failed'],
                'peak'       => $result['pending_remaining'],
                'peak2'      => $result['pending_remaining'],
                'time'       => time(),
                'time2'      => time(),
            ]
        );
    } catch (\Exception $e) {
        Logger::exception($e, 'Failed to update daily stats');
    }
}

// ══════════════════════════════════════════════════════════
// Execute
// ══════════════════════════════════════════════════════════

$exitCode = main();
exit($exitCode);
