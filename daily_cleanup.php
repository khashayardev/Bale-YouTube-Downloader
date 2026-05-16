<?php
/**
 * ============================================================
 * daily_cleanup.php — Daily Maintenance Script
 * ============================================================
 * 
 * 🏗️ Architecture Layer: 4 - Utilities (Cron Job)
 * 📦 Part of: Khashayar YouTube Downloader - Queue System
 * 🔗 Dependency: Database (File 3), Logger (File 2)
 * 
 * 🎯 Purpose:
 *   • Daily database cleanup and optimization
 *   • Remove old completed/failed jobs (24+ hours)
 *   • VACUUM SQLite to reclaim disk space
 *   • Rotate and compress log files
 *   • Create database backups with retention policy
 *   • Clean up old backups (7+ days)
 *   • Reset daily statistics counters
 *   • Report cleanup results via log
 * 
 * ⚙️ Cron Setup (cPanel):
 *   0 3 * * * /usr/bin/php /home/user/public_html/daily_cleanup.php
 *   (Runs at 3:00 AM every day)
 * 
 * 🔒 Security:
 *   • CLI-only execution (cannot be called from web)
 *   • Lock file prevents concurrent execution
 *   • Secret token required for HTTP access (optional)
 *   • All actions logged for audit trail
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

if (php_sapi_name() !== 'cli') {
    // Optional: Allow HTTP access with secret token for manual trigger
    $secretToken = $_GET['token'] ?? '';
    $configuredToken = getenv('CLEANUP_SECRET_TOKEN') ?: '';
    
    if (empty($configuredToken) || $secretToken !== $configuredToken) {
        http_response_code(403);
        die('⛔ This script can only be run from command line (cron) or with valid token.');
    }
}

// ══════════════════════════════════════════════════════════
// Bootstrap
// ══════════════════════════════════════════════════════════

define('APP_RUNNING', true);

require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Logger.php';
require_once __DIR__ . '/lib/Database.php';

// ══════════════════════════════════════════════════════════
// Lock Management
// ══════════════════════════════════════════════════════════

/**
 * Acquire cleanup lock
 * 
 * @return string|false Lock file path or false if already locked
 */
function acquireCleanupLock(): string|false
{
    $lockFile = DATA_DIR . '/daily_cleanup.lock';
    
    if (file_exists($lockFile)) {
        $lockAge = time() - filemtime($lockFile);
        
        // If lock is older than 2 hours, it's stale
        if ($lockAge > 7200) {
            Logger::warning('Stale cleanup lock detected, overriding', ['age_hours' => round($lockAge / 3600, 1)]);
            @unlink($lockFile);
        } else {
            Logger::info('Cleanup already running or recently completed');
            return false;
        }
    }
    
    if (@file_put_contents($lockFile, (string) getmypid(), LOCK_EX) === false) {
        Logger::error('Failed to create cleanup lock file');
        return false;
    }
    
    return $lockFile;
}

/**
 * Release cleanup lock
 * 
 * @param string $lockFile Lock file path
 * @return void
 */
function releaseCleanupLock(string $lockFile): void
{
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
}

// ══════════════════════════════════════════════════════════
// Cleanup Functions
// ══════════════════════════════════════════════════════════

/**
 * Clean up old jobs from database
 * Removes completed and failed jobs older than 24 hours
 * 
 * @param Database $db Database instance
 * @return int Number of jobs deleted
 */
function cleanupOldJobs(Database $db): int
{
    $cutoff = time() - (24 * 3600); // 24 hours ago
    
    // First, delete from active_jobs (child table)
    $db->execute(
        "DELETE FROM active_jobs WHERE queue_id IN 
         (SELECT id FROM pending_queue WHERE completed_at < :cutoff AND status IN ('completed', 'failed'))",
        ['cutoff' => $cutoff]
    );
    $activeDeleted = $db->affectedRows();
    
    // Then, delete from pending_queue (parent table)
    $db->execute(
        "DELETE FROM pending_queue WHERE completed_at < :cutoff AND status IN ('completed', 'failed')",
        ['cutoff' => $cutoff]
    );
    $pendingDeleted = $db->affectedRows();
    
    $totalDeleted = $activeDeleted + $pendingDeleted;
    
    if ($totalDeleted > 0) {
        Logger::info('Old jobs cleaned up', [
            'jobs_deleted'    => $pendingDeleted,
            'active_deleted'  => $activeDeleted,
            'cutoff_date'     => date('Y-m-d H:i:s', $cutoff),
        ]);
    }
    
    return $totalDeleted;
}

/**
 * Clean up old processed updates (Telegram deduplication)
 * Keeps last 48 hours of update IDs
 * 
 * @param Database $db Database instance
 * @return int Number of records deleted
 */
function cleanupProcessedUpdates(Database $db): int
{
    $cutoff = time() - (48 * 3600); // 48 hours
    
    $db->execute(
        "DELETE FROM processed_updates WHERE processed_at < :cutoff",
        ['cutoff' => $cutoff]
    );
    
    $deleted = $db->affectedRows();
    
    if ($deleted > 0) {
        Logger::info('Processed updates cleaned', ['deleted' => $deleted]);
    }
    
    return $deleted;
}

/**
 * Clean up expired file ID cache
 * File IDs expire after FILE_RETENTION_MINUTES * 2 for safety
 * 
 * @param Database $db Database instance
 * @return int Number of records deleted
 */
function cleanupFileIdCache(Database $db): int
{
    $cutoff = time() - (FILE_RETENTION_MINUTES * 60 * 2);
    
    $db->execute(
        "DELETE FROM file_id_cache WHERE created_at < :cutoff",
        ['cutoff' => $cutoff]
    );
    
    $deleted = $db->affectedRows();
    
    if ($deleted > 0) {
        Logger::info('File ID cache cleaned', ['deleted' => $deleted]);
    }
    
    return $deleted;
}

/**
 * Clean up old rate limit records (7+ days inactive)
 * 
 * @param Database $db Database instance
 * @return int Number of records deleted
 */
function cleanupRateLimits(Database $db): int
{
    $cutoff = time() - (7 * 86400); // 7 days
    
    $db->execute(
        "DELETE FROM rate_limits WHERE last_request_time < :cutoff AND request_count_today = 0",
        ['cutoff' => $cutoff]
    );
    
    $deleted = $db->affectedRows();
    
    if ($deleted > 0) {
        Logger::info('Old rate limit records cleaned', ['deleted' => $deleted]);
    }
    
    return $deleted;
}

/**
 * Optimize database
 * Runs VACUUM to reclaim disk space
 * 
 * @param Database $db Database instance
 * @return array{ before_mb: float, after_mb: float, saved_mb: float }
 */
function optimizeDatabase(Database $db): array
{
    $before = filesize(DB_PATH) ?: 0;
    
    // Run WAL checkpoint first
    $db->checkpoint();
    
    // Run VACUUM to reclaim space
    $db->vacuum();
    
    $after = filesize(DB_PATH) ?: 0;
    
    $result = [
        'before_mb' => round($before / 1024 / 1024, 2),
        'after_mb'  => round($after / 1024 / 1024, 2),
        'saved_mb'  => round(($before - $after) / 1024 / 1024, 2),
    ];
    
    Logger::info('Database optimized', $result);
    
    return $result;
}

/**
 * Create database backup
 * 
 * @param Database $db Database instance
 * @return string|null Backup path or null on failure
 */
function createBackup(Database $db): ?string
{
    return $db->backup();
}

/**
 * Clean up old database backups (7+ days)
 * 
 * @param Database $db Database instance
 * @return int Number of backups deleted
 */
function cleanupOldBackups(Database $db): int
{
    return $db->cleanupBackups();
}

/**
 * Rotate and clean log files
 * 
 * @return array{ queue_log_mb: float, error_log_mb: float, rotated: int }
 */
function rotateLogs(): array
{
    $result = [
        'queue_log_mb'  => 0,
        'error_log_mb'  => 0,
        'rotated'       => 0,
    ];
    
    $logFiles = [
        LOG_QUEUE  => 'queue',
        LOG_ERRORS => 'error',
    ];
    
    foreach ($logFiles as $logPath => $logName) {
        if (!file_exists($logPath)) {
            continue;
        }
        
        $size = filesize($logPath) ?: 0;
        $sizeMB = round($size / 1024 / 1024, 2);
        $result[$logName . '_log_mb'] = $sizeMB;
        
        // Rotate if larger than max size
        if ($size > LOG_MAX_SIZE) {
            $backupPath = $logPath . '.' . date('Y-m-d');
            
            if (rename($logPath, $backupPath)) {
                // Create fresh empty log file
                @touch($logPath);
                @chmod($logPath, 0640);
                $result['rotated']++;
                
                Logger::info("Log rotated: {$logName}", [
                    'size_mb' => $sizeMB,
                    'backup'  => basename($backupPath),
                ]);
            }
        }
    }
    
    // Clean up old rotated logs (keep only LOG_ROTATION_COUNT)
    foreach ($logFiles as $logPath => $logName) {
        $rotatedFiles = glob($logPath . '.*');
        if ($rotatedFiles === false || count($rotatedFiles) <= LOG_ROTATION_COUNT) {
            continue;
        }
        
        // Sort by modification time (oldest first)
        usort($rotatedFiles, function ($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        // Delete oldest files exceeding rotation count
        $toDelete = array_slice($rotatedFiles, 0, count($rotatedFiles) - LOG_ROTATION_COUNT);
        foreach ($toDelete as $file) {
            @unlink($file);
        }
    }
    
    return $result;
}

/**
 * Reset daily statistics counters
 * Creates a new entry for today if not exists
 * 
 * @param Database $db Database instance
 * @return void
 */
function resetDailyStats(Database $db): void
{
    $today = date('Y-m-d');
    
    // Check if today's entry exists
    $exists = $db->fetchValue(
        "SELECT COUNT(*) FROM queue_stats_daily WHERE date = :date",
        ['date' => $today]
    );
    
    if (!$exists) {
        $db->execute(
            "INSERT INTO queue_stats_daily (date, total_requests, completed_requests, failed_requests, peak_queue_length, updated_at)
             VALUES (:date, 0, 0, 0, 0, :time)",
            [
                'date' => $today,
                'time' => time(),
            ]
        );
        
        Logger::info('New daily stats record created', ['date' => $today]);
    }
}

/**
 * Clean up temporary files in data directory
 * 
 * @return int Number of files deleted
 */
function cleanupTempFiles(): int
{
    $deleted = 0;
    $tempPatterns = ['*.tmp', '*.temp', '*.cache'];
    
    foreach ($tempPatterns as $pattern) {
        $files = glob(DATA_DIR . '/' . $pattern);
        if ($files === false) {
            continue;
        }
        
        foreach ($files as $file) {
            if (is_file($file) && @unlink($file)) {
                $deleted++;
            }
        }
    }
    
    if ($deleted > 0) {
        Logger::info('Temporary files cleaned', ['deleted' => $deleted]);
    }
    
    return $deleted;
}

// ══════════════════════════════════════════════════════════
// Main Cleanup Routine
// ══════════════════════════════════════════════════════════

/**
 * Run all cleanup tasks
 * 
 * @return array<string, mixed> Cleanup results
 */
function runCleanup(): array
{
    $startTime = microtime(true);
    $results = [
        'start_time'      => date('Y-m-d H:i:s'),
        'jobs_deleted'    => 0,
        'updates_deleted' => 0,
        'cache_deleted'   => 0,
        'rate_deleted'    => 0,
        'backups_deleted' => 0,
        'temp_deleted'    => 0,
        'logs_rotated'    => 0,
        'db_optimized'    => false,
        'backup_created'  => false,
        'db_before_mb'    => 0,
        'db_after_mb'     => 0,
        'db_saved_mb'     => 0,
        'duration_seconds' => 0,
        'end_time'        => '',
    ];
    
    try {
        $db = Database::getInstance();
        
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "  Daily Cleanup v5.0 — " . date('Y-m-d H:i:s') . "\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        // 1. Clean up old jobs
        echo "🗑️  Cleaning old jobs...\n";
        $results['jobs_deleted'] = cleanupOldJobs($db);
        echo "   ✅ {$results['jobs_deleted']} jobs deleted\n";
        
        // 2. Clean up processed updates
        echo "🗑️  Cleaning processed updates...\n";
        $results['updates_deleted'] = cleanupProcessedUpdates($db);
        echo "   ✅ {$results['updates_deleted']} records deleted\n";
        
        // 3. Clean up file ID cache
        echo "🗑️  Cleaning file ID cache...\n";
        $results['cache_deleted'] = cleanupFileIdCache($db);
        echo "   ✅ {$results['cache_deleted']} records deleted\n";
        
        // 4. Clean up rate limits
        echo "🗑️  Cleaning old rate limits...\n";
        $results['rate_deleted'] = cleanupRateLimits($db);
        echo "   ✅ {$results['rate_deleted']} records deleted\n";
        
        // 5. Reset daily stats
        echo "📊 Resetting daily stats...\n";
        resetDailyStats($db);
        echo "   ✅ Stats reset complete\n";
        
        // 6. Clean up temporary files
        echo "🗑️  Cleaning temp files...\n";
        $results['temp_deleted'] = cleanupTempFiles();
        echo "   ✅ {$results['temp_deleted']} files deleted\n";
        
        // 7. Create backup before optimization
        echo "💾 Creating database backup...\n";
        $backupPath = createBackup($db);
        $results['backup_created'] = $backupPath !== null;
        echo $backupPath 
            ? "   ✅ Backup: " . basename($backupPath) . "\n"
            : "   ❌ Backup failed\n";
        
        // 8. Clean up old backups
        echo "🗑️  Cleaning old backups...\n";
        $results['backups_deleted'] = cleanupOldBackups($db);
        echo "   ✅ {$results['backups_deleted']} backups deleted\n";
        
        // 9. Optimize database
        echo "⚡ Optimizing database...\n";
        $optimization = optimizeDatabase($db);
        $results['db_optimized'] = true;
        $results['db_before_mb'] = $optimization['before_mb'];
        $results['db_after_mb'] = $optimization['after_mb'];
        $results['db_saved_mb'] = $optimization['saved_mb'];
        echo "   ✅ Before: {$optimization['before_mb']}MB → After: {$optimization['after_mb']}MB";
        if ($optimization['saved_mb'] > 0) {
            echo " (Saved: {$optimization['saved_mb']}MB)";
        }
        echo "\n";
        
        // 10. Rotate logs
        echo "📝 Rotating logs...\n";
        $logResult = rotateLogs();
        $results['logs_rotated'] = $logResult['rotated'];
        echo "   ✅ Queue: {$logResult['queue_log_mb']}MB | Error: {$logResult['error_log_mb']}MB | Rotated: {$logResult['rotated']}\n";
        
        // Calculate duration
        $results['duration_seconds'] = round(microtime(true) - $startTime, 2);
        $results['end_time'] = date('Y-m-d H:i:s');
        
        echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "  ✅ Cleanup Complete — {$results['duration_seconds']}s\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        Logger::info('Daily cleanup completed', $results);
        
    } catch (\Exception $e) {
        Logger::exception($e, 'Daily cleanup failed');
        
        echo "\n❌ Cleanup failed: {$e->getMessage()}\n";
        echo "Check logs for details.\n";
        
        $results['error'] = $e->getMessage();
    }
    
    return $results;
}

// ══════════════════════════════════════════════════════════
// Execute
// ══════════════════════════════════════════════════════════

$lockFile = acquireCleanupLock();

if ($lockFile === false) {
    echo "⏭️  Cleanup already running or recently completed. Skipping.\n";
    exit(1);
}

echo "🔒 Lock acquired: " . basename($lockFile) . "\n\n";

try {
    $results = runCleanup();
    
    // Output final status
    if (isset($results['error'])) {
        echo "\n⚠️  Cleanup completed with errors.\n";
        exit(2);
    }
    
    echo "\n🎉 All tasks completed successfully!\n";
    exit(0);
    
} finally {
    // Always release lock
    releaseCleanupLock($lockFile);
    echo "\n🔓 Lock released.\n";
}