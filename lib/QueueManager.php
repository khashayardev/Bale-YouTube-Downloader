<?php
/**
 * ============================================================
 * QueueManager.php — Central Queue Orchestrator
 * ============================================================
 * 
 * 🏗️ Architecture Layer: 2 - Business Logic
 * 📦 Part of: Khashayar YouTube Downloader - Queue System
 * 🔗 Dependency: All Layer 0 & Layer 1 files (Files 1-6) ✅
 * 
 * 🎯 Purpose:
 *   • Central orchestrator for the entire queue system
 *   • Job lifecycle management: create → queue → dispatch → monitor → complete
 *   • Intelligent batch processing with GitHub capacity awareness
 *   • Duplicate detection and prevention
 *   • Job status tracking and user notification
 *   • Queue statistics and monitoring
 *   • Graceful degradation when GitHub is unavailable
 * 
 * 🔄 Core Workflow:
 *   1. User sends link → addToQueue() → stored as 'pending'
 *   2. Cron runs → processBatch() → picks oldest 'pending' jobs
 *   3. Checks GitHub capacity → dispatches up to batch size
 *   4. Marks as 'dispatched' → monitors via checkCompletedJobs()
 *   5. On completion → notifies user + marks 'completed'
 *   6. Failed jobs → retry logic with max attempts
 * 
 * 🔒 Design Decisions:
 *   • FIFO ordering with priority support (future)
 *   • Duplicate detection: same user + same URL + pending = skip
 *   • Max 3 pending jobs per user (prevents abuse)
 *   • Job timeout: 30 minutes (stale jobs auto-cancelled)
 *   • Retry: max 3 attempts with 5-minute delay
 *   • Batch processing: respects GitHub rate limits
 * 
 * 🔧 PATCHED v5.0.1: All chatId params accept string|int
 *   Reason: Bale sends chat_id as integer (e.g. 241726352) but strict_types=1
 *   requires exact type match. Using union type string|int fixes the TypeError.
 * 
 * @package     KhashayarDownloader
 * @version     5.0.1
 * @author      Khashayar
 * @license     MIT
 * @since       2026-05-16
 */

declare(strict_types=1);

// Guard: All dependencies must be loaded
if (!class_exists('Database') || !class_exists('GitHubClient') || 
    !class_exists('RateLimiter') || !class_exists('BaleNotifier')) {
    die('⛔ QueueManager requires all Layer 0 and Layer 1 files to be loaded first.');
}

/**
 * ============================================================
 * QueueManager Class
 * ============================================================
 */
class QueueManager
{
    /** @var Database Database instance */
    private Database $db;

    /** @var GitHubClient GitHub API client */
    private GitHubClient $github;

    /** @var RateLimiter Rate limiter instance */
    private RateLimiter $rateLimiter;

    /** @var int Batch size per cron execution */
    private int $batchSize;

    /** @var int Seconds between dispatches */
    private int $dispatchDelay;

    /** @var int Max pending jobs per user */
    private int $maxPerUser;

    /** @var int Job timeout in seconds */
    private int $jobTimeout;

    /** @var int Max retry attempts */
    private int $maxRetries;

    /**
     * Constructor
     * 
     * @param Database|null $db Database instance
     * @param GitHubClient|null $github GitHub client
     * @param RateLimiter|null $rateLimiter Rate limiter
     */
    public function __construct(
        ?Database $db = null,
        ?GitHubClient $github = null,
        ?RateLimiter $rateLimiter = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->github = $github ?? new GitHubClient();
        $this->rateLimiter = $rateLimiter ?? new RateLimiter($this->db);

        $this->batchSize = defined('QUEUE_BATCH_SIZE') ? QUEUE_BATCH_SIZE : 10;
        $this->dispatchDelay = defined('QUEUE_DISPATCH_DELAY') ? QUEUE_DISPATCH_DELAY : 2;
        $this->maxPerUser = defined('QUEUE_MAX_PER_USER') ? QUEUE_MAX_PER_USER : 3;
        $this->jobTimeout = defined('QUEUE_JOB_TIMEOUT') ? QUEUE_JOB_TIMEOUT : 1800;
        $this->maxRetries = defined('GITHUB_API_MAX_RETRIES') ? GITHUB_API_MAX_RETRIES : 3;
    }

    /**
     * ============================================================
     * Job Creation
     * ============================================================
     */

    /**
     * Add a download job to the queue
     * 
     * @param string|int $chatId User's chat ID
     * @param string $youtubeUrl YouTube URL to download
     * @param string $quality Quality setting
     * @param bool $subtitles Whether to include subtitles
     * @return array{success: bool, position: int, estimated_wait: int, message: string}
     */
    public function addToQueue(
        string|int $chatId,
        string $youtubeUrl,
        string $quality = 'best',
        bool $subtitles = false
    ): array {
        try {
            // 1. Validate inputs
            if (empty($youtubeUrl) || !filter_var($youtubeUrl, FILTER_VALIDATE_URL)) {
                return [
                    'success' => false,
                    'position' => 0,
                    'estimated_wait' => 0,
                    'message' => 'لینک یوتیوب نامعتبر است.',
                ];
            }

            if (!in_array($quality, VALID_QUALITIES, true)) {
                $quality = 'best';
            }

            // 2. Check user's pending job count
            $userPendingCount = $this->getUserPendingCount($chatId);
            if ($userPendingCount >= $this->maxPerUser) {
                Logger::info('User has max pending jobs', [
                    'chat_id' => $chatId,
                    'pending' => $userPendingCount,
                ]);
                return [
                    'success' => false,
                    'position' => 0,
                    'estimated_wait' => 0,
                    'message' => "⚠️ شما حداکثر {$this->maxPerUser} دانلود در صف دارید.\nلطفاً صبر کنید تا یکی کامل شود.",
                ];
            }

            // 3. Check for duplicate (same user + same URL + pending status)
            if ($this->isDuplicate($chatId, $youtubeUrl)) {
                Logger::info('Duplicate job rejected', [
                    'chat_id' => $chatId,
                    'url'     => substr($youtubeUrl, 0, 50),
                ]);
                return [
                    'success' => false,
                    'position' => 0,
                    'estimated_wait' => 0,
                    'message' => '⚠️ این لینک قبلاً در صف دانلود شما قرار دارد.',
                ];
            }

            // 4. Check queue capacity
            $queueSize = $this->getQueueSize();
            $maxQueue = defined('QUEUE_MAX_SIZE') ? QUEUE_MAX_SIZE : 2000;
            if ($queueSize >= $maxQueue) {
                Logger::warning('Queue at capacity', ['size' => $queueSize]);
                return [
                    'success' => false,
                    'position' => 0,
                    'estimated_wait' => 0,
                    'message' => '⚠️ صف دانلود در حال حاضر پر است. لطفاً چند دقیقه دیگر تلاش کنید.',
                ];
            }

            // 5. Insert job into queue
            $now = time();
            $this->db->execute(
                "INSERT INTO pending_queue 
                 (chat_id, youtube_url, quality, subtitles, status, created_at) 
                 VALUES (:chat_id, :url, :quality, :subs, 'pending', :time)",
                [
                    'chat_id'  => $chatId,
                    'url'      => $youtubeUrl,
                    'quality'  => $quality,
                    'subs'     => $subtitles ? 'yes' : 'no',
                    'time'     => $now,
                ]
            );

            $jobId = $this->db->lastInsertId();

            // 6. Calculate queue position
            $position = $this->getQueuePosition($jobId);

            // 7. Estimate wait time
            $estimatedWait = $this->estimateWaitTime($position);

            Logger::info('Job added to queue', [
                'job_id'   => $jobId,
                'chat_id'  => $chatId,
                'position' => $position,
                'quality'  => $quality,
            ]);

            return [
                'success'        => true,
                'position'       => $position,
                'estimated_wait' => $estimatedWait,
                'message'        => getQueueStatusMessage($position),
            ];

        } catch (\Exception $e) {
            Logger::exception($e, 'Failed to add job to queue');
            return [
                'success' => false,
                'position' => 0,
                'estimated_wait' => 0,
                'message' => '❌ خطای سیستمی. لطفاً دوباره تلاش کنید.',
            ];
        }
    }

    /**
     * Check if user already has this URL pending
     * 
     * @param string|int $chatId User's chat ID
     * @param string $youtubeUrl YouTube URL
     * @return bool True if duplicate exists
     */
    private function isDuplicate(string|int $chatId, string $youtubeUrl): bool
    {
        $count = $this->db->fetchValue(
            "SELECT COUNT(*) FROM pending_queue 
             WHERE chat_id = :chat_id 
             AND youtube_url = :url 
             AND status IN ('pending', 'dispatched')",
            [
                'chat_id' => $chatId,
                'url'     => $youtubeUrl,
            ]
        );

        return ($count !== null && (int) $count > 0);
    }

    /**
     * ============================================================
     * Batch Processing (called by queue_processor.php via cron)
     * ============================================================
     */

    /**
     * Process a batch of pending jobs
     * Called by cron job every 15-30 seconds
     * 
     * @return array{processed: int, dispatched: int, skipped: int, errors: int, pending_remaining: int}
     */
    public function processBatch(): array
    {
        $startTime = microtime(true);
        $stats = [
            'processed'  => 0,
            'dispatched' => 0,
            'skipped'    => 0,
            'errors'     => 0,
            'pending_remaining' => 0,
        ];

        try {
            // 1. Check if GitHub is available
            if (!$this->github->canDispatch()) {
                Logger::info('GitHub cannot accept dispatches, skipping batch');
                
                // Refresh rate limit for next run
                $this->github->fetchRateLimit();
                
                $stats['pending_remaining'] = $this->getQueueSize();
                $stats['skipped'] = $stats['pending_remaining'];
                return $stats;
            }

            // 2. Get available slots
            $availableSlots = $this->github->getAvailableSlots();
            $toProcess = min($this->batchSize, $availableSlots);

            if ($toProcess <= 0) {
                Logger::info('No available slots for dispatch');
                $stats['pending_remaining'] = $this->getQueueSize();
                return $stats;
            }

            // 3. Clean up stale jobs first
            $this->cleanupStaleJobs();

            // 4. Fetch next batch of pending jobs (FIFO: oldest first)
            $jobs = $this->db->fetchAll(
                "SELECT id, chat_id, youtube_url, quality, subtitles, retry_count
                 FROM pending_queue 
                 WHERE status = 'pending' 
                 ORDER BY priority DESC, created_at ASC 
                 LIMIT :limit",
                ['limit' => $toProcess]
            );

            if (empty($jobs)) {
                Logger::debug('No pending jobs to process');
                $stats['pending_remaining'] = 0;
                return $stats;
            }

            Logger::info('Processing batch', [
                'jobs_to_process' => count($jobs),
                'available_slots' => $availableSlots,
            ]);

            // 5. Process each job
            foreach ($jobs as $job) {
                $stats['processed']++;

                try {
                    // Small delay between dispatches to avoid rate limit
                    if ($stats['dispatched'] > 0) {
                        sleep($this->dispatchDelay);
                    }

                    $result = $this->dispatchJob($job);

                    if ($result['success']) {
                        $stats['dispatched']++;
                    } else {
                        $stats['errors']++;
                        
                        // Increment retry count
                        $this->db->execute(
                            "UPDATE pending_queue SET retry_count = retry_count + 1 WHERE id = :id",
                            ['id' => $job['id']]
                        );

                        // If max retries exceeded, mark as failed
                        if (($job['retry_count'] + 1) >= $this->maxRetries) {
                            $this->markJobFailed(
                                (int) $job['id'],
                                $job['chat_id'],
                                'Exceeded max retry attempts'
                            );
                        }
                    }
                } catch (\Exception $e) {
                    Logger::exception($e, 'Error processing job', ['job_id' => $job['id']]);
                    $stats['errors']++;
                }
            }

            // 6. Refresh rate limit info for next run
            if ($stats['dispatched'] > 5) {
                $this->github->fetchRateLimit();
            }

            $stats['pending_remaining'] = $this->getQueueSize();

            Logger::queueStats(
                $stats['processed'],
                $stats['pending_remaining'],
                microtime(true) - $startTime
            );

        } catch (\Exception $e) {
            Logger::exception($e, 'Batch processing failed');
        }

        return $stats;
    }

    /**
     * Dispatch a single job to GitHub Actions
     * 
     * @param array $job Job data from database
     * @return array{success: bool, error: string}
     */
    private function dispatchJob(array $job): array
    {
        $jobId = (int) $job['id'];
        $chatId = $job['chat_id'];
        $youtubeUrl = $job['youtube_url'];
        $quality = $job['quality'];
        $subtitles = ($job['subtitles'] === 'yes');

        Logger::info('Dispatching job', [
            'job_id'   => $jobId,
            'chat_id'  => $chatId,
            'quality'  => $quality,
        ]);

        // Mark as dispatched
        $this->db->execute(
            "UPDATE pending_queue SET status = 'dispatched', processed_at = :time WHERE id = :id",
            [
                'id'   => $jobId,
                'time' => time(),
            ]
        );

        // Get user's channel ID from database
        $userChannel = $this->db->fetchOne(
            "SELECT channel_id, channel_username FROM user_channels WHERE chat_id = :chat_id AND is_active = 1",
            ['chat_id' => $chatId]
        );
        $channelId = $userChannel['channel_id'] ?? '';
        $channelUsername = $userChannel['channel_username'] ?? '';

        // Dispatch to GitHub
        $result = $this->github->dispatchDownload($youtubeUrl, $quality, $subtitles, '', (string) $chatId, $channelId, $channelUsername);

        if ($result['success']) {
            // Try to get run ID from response (may not be available immediately)
            $runId = $result['body']['run_id'] ?? null;
            
            // Add to active jobs tracking
            $this->db->execute(
                "INSERT INTO active_jobs (queue_id, github_run_id, status, started_at, checked_at)
                 VALUES (:queue_id, :run_id, 'running', :started, :checked)",
                [
                    'queue_id' => $jobId,
                    'run_id'   => $runId ?? 'pending_' . $jobId,
                    'started'  => time(),
                    'checked'  => time(),
                ]
            );

            // Notify user their download started
            BaleNotifier::sendMessage(
                $chatId,
                "🚀 *دانلود شما شروع شد!*\n\n⏱ *زمان تقریبی:* ۲ تا ۵ دقیقه\n\n👇 برای بررسی وضعیت، دکمه زیر را بزنید:",
                BaleNotifier::statusCheckKeyboard()
            );

            // Update notification flag
            $this->db->execute(
                "UPDATE pending_queue SET notified_start = 1 WHERE id = :id",
                ['id' => $jobId]
            );

            return ['success' => true, 'error' => ''];
        }

        // Dispatch failed — mark as pending for retry
        $this->db->execute(
            "UPDATE pending_queue SET status = 'pending', processed_at = NULL WHERE id = :id",
            ['id' => $jobId]
        );

        Logger::error('Job dispatch failed', [
            'job_id'    => $jobId,
            'http_code' => $result['http_code'],
        ]);

        return [
            'success' => false,
            'error'   => "HTTP {$result['http_code']}: Dispatch failed",
        ];
    }

    /**
     * ============================================================
     * Job Completion & Status
     * ============================================================
     */

    /**
     * Check status of all dispatched jobs
     * Called periodically to update job statuses
     * 
     * @return array{checked: int, completed: int, failed: int, still_running: int}
     */
    public function checkCompletedJobs(): array
    {
        $stats = [
            'checked'       => 0,
            'completed'     => 0,
            'failed'        => 0,
            'still_running' => 0,
        ];

        try {
            // Get all dispatched jobs
            $jobs = $this->db->fetchAll(
                "SELECT pq.id, pq.chat_id, pq.youtube_url, aj.github_run_id
                 FROM pending_queue pq
                 JOIN active_jobs aj ON pq.id = aj.queue_id
                 WHERE pq.status = 'dispatched' AND aj.status = 'running'"
            );

            foreach ($jobs as $job) {
                $stats['checked']++;
                $runId = $job['github_run_id'];

                // Skip jobs without real run IDs
                if (str_starts_with($runId, 'pending_')) {
                    $stats['still_running']++;
                    continue;
                }

                // Check GitHub status
                $status = $this->github->getWorkflowRunStatus($runId);

                switch ($status['status']) {
                    case 'completed':
                        if ($status['conclusion'] === 'success') {
                            $this->markJobCompleted(
                                (int) $job['id'],
                                $job['chat_id']
                            );
                            $stats['completed']++;
                        } else {
                            $this->markJobFailed(
                                (int) $job['id'],
                                $job['chat_id'],
                                "Workflow {$status['conclusion']}"
                            );
                            $stats['failed']++;
                        }
                        break;

                    case 'in_progress':
                    case 'queued':
                    case 'pending':
                        $stats['still_running']++;
                        // Update checked_at timestamp
                        $this->db->execute(
                            "UPDATE active_jobs SET checked_at = :time WHERE github_run_id = :run_id",
                            [
                                'time'   => time(),
                                'run_id' => $runId,
                            ]
                        );
                        break;

                    default:
                        Logger::warning('Unknown job status', [
                            'job_id' => $job['id'],
                            'status' => $status['status'],
                        ]);
                        $stats['still_running']++;
                        break;
                }

                // Rate limit protection: don't check too many at once
                if ($stats['checked'] >= 20) {
                    break;
                }
            }

        } catch (\Exception $e) {
            Logger::exception($e, 'Error checking completed jobs');
        }

        if ($stats['completed'] > 0 || $stats['failed'] > 0) {
            Logger::info('Job status check completed', $stats);
        }

        return $stats;
    }

    /**
     * Mark a job as completed
     * 
     * @param int $jobId Job ID
     * @param string|int $chatId User's chat ID
     * @return void
     */
    private function markJobCompleted(int $jobId, string|int $chatId): void
    {
        $this->db->execute(
            "UPDATE pending_queue SET status = 'completed', completed_at = :time WHERE id = :id",
            [
                'id'   => $jobId,
                'time' => time(),
            ]
        );

        $this->db->execute(
            "UPDATE active_jobs SET status = 'completed' WHERE queue_id = :id",
            ['id' => $jobId]
        );

        // Get file info for notification
        $job = $this->db->fetchOne(
            "SELECT youtube_url, quality FROM pending_queue WHERE id = :id",
            ['id' => $jobId]
        );

        if ($job && !$this->isAlreadyNotifiedDone($jobId)) {
            BaleNotifier::sendMessage(
                $chatId,
                "✅ *دانلود شما کامل شد!*\n\n📁 فایل در کانال آرشیو ذخیره شده است.\n\n🔄 برای دریافت فایل، دکمه بررسی وضعیت را بزنید.",
                ['inline_keyboard' => [[['text' => '🔄 بررسی وضعیت', 'callback_data' => 'check_status']]]]
            );

            $this->db->execute(
                "UPDATE pending_queue SET notified_done = 1 WHERE id = :id",
                ['id' => $jobId]
            );
        }

        Logger::info('Job marked as completed', ['job_id' => $jobId]);
    }

    /**
     * Mark a job as failed
     * 
     * @param int $jobId Job ID
     * @param string|int $chatId User's chat ID
     * @param string $reason Failure reason
     * @return void
     */
    private function markJobFailed(int $jobId, string|int $chatId, string $reason): void
    {
        $this->db->execute(
            "UPDATE pending_queue SET status = 'failed', error_message = :reason, completed_at = :time WHERE id = :id",
            [
                'id'     => $jobId,
                'reason' => $reason,
                'time'   => time(),
            ]
        );

        $this->db->execute(
            "UPDATE active_jobs SET status = 'failed' WHERE queue_id = :id",
            ['id' => $jobId]
        );

        // Notify user
        if (!$this->isAlreadyNotifiedDone($jobId)) {
            BaleNotifier::notifyDownloadFailed($chatId, $reason);

            $this->db->execute(
                "UPDATE pending_queue SET notified_done = 1 WHERE id = :id",
                ['id' => $jobId]
            );
        }

        Logger::info('Job marked as failed', [
            'job_id' => $jobId,
            'reason' => $reason,
        ]);
    }

    /**
     * Check if user was already notified about job completion
     * 
     * @param int $jobId Job ID
     * @return bool True if already notified
     */
    private function isAlreadyNotifiedDone(int $jobId): bool
    {
        $notified = $this->db->fetchValue(
            "SELECT notified_done FROM pending_queue WHERE id = :id",
            ['id' => $jobId]
        );

        return ($notified !== null && (int) $notified === 1);
    }

    /**
     * ============================================================
     * Queue Information
     * ============================================================
     */

    /**
     * Get current queue size (pending + dispatched jobs)
     * 
     * @return int Number of jobs in queue
     */
    public function getQueueSize(): int
    {
        $count = $this->db->fetchValue(
            "SELECT COUNT(*) FROM pending_queue WHERE status IN ('pending', 'dispatched')"
        );

        return $count !== null ? (int) $count : 0;
    }

    /**
     * Get user's position in queue for a specific job
     * 
     * @param int $jobId Job ID
     * @return int Position (1-based)
     */
    public function getQueuePosition(int $jobId): int
    {
        $job = $this->db->fetchOne(
            "SELECT created_at, priority FROM pending_queue WHERE id = :id",
            ['id' => $jobId]
        );

        if (!$job) {
            return 0;
        }

        $count = $this->db->fetchValue(
            "SELECT COUNT(*) FROM pending_queue 
             WHERE status = 'pending' 
             AND (priority > :priority OR (priority = :priority2 AND created_at <= :time))",
            [
                'priority'  => $job['priority'],
                'priority2' => $job['priority'],
                'time'      => $job['created_at'],
            ]
        );

        return ($count !== null ? (int) $count : 0);
    }

    /**
     * Estimate wait time based on position
     * 
     * @param int $position Queue position
     * @return int Estimated seconds
     */
    public function estimateWaitTime(int $position): int
    {
        if ($position <= 0) {
            return 0;
        }

        $interval = defined('QUEUE_PROCESS_INTERVAL') ? QUEUE_PROCESS_INTERVAL : 15;
        $batchSize = $this->batchSize;

        // Batches needed to reach this position
        $batchesNeeded = ceil($position / $batchSize);
        $waitSeconds = ($batchesNeeded - 1) * $interval + ($interval / 2);

        return (int) $waitSeconds;
    }

    /**
     * Get user's pending job count
     * 
     * @param string|int $chatId User's chat ID
     * @return int Number of pending jobs
     */
    public function getUserPendingCount(string|int $chatId): int
    {
        $count = $this->db->fetchValue(
            "SELECT COUNT(*) FROM pending_queue 
             WHERE chat_id = :chat_id AND status IN ('pending', 'dispatched')",
            ['chat_id' => $chatId]
        );

        return $count !== null ? (int) $count : 0;
    }

    /**
     * Get user's job status
     * 
     * @param string|int $chatId User's chat ID
     * @return array|null Job status info or null
     */
    public function getUserJobStatus(string|int $chatId): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, status, youtube_url, quality, created_at, 
                    completed_at, error_message, retry_count
             FROM pending_queue 
             WHERE chat_id = :chat_id 
             ORDER BY created_at DESC 
             LIMIT 1",
            ['chat_id' => $chatId]
        );
    }

    /**
     * ============================================================
     * Maintenance & Cleanup
     * ============================================================
     */

    /**
     * Clean up stale jobs (pending too long)
     * 
     * @return int Number of jobs cleaned
     */
    public function cleanupStaleJobs(): int
    {
        $cutoff = time() - $this->jobTimeout;

        $result = $this->db->execute(
            "UPDATE pending_queue SET status = 'failed', error_message = 'Job timed out', completed_at = :time
             WHERE status IN ('pending', 'dispatched') AND created_at < :cutoff",
            [
                'time'   => time(),
                'cutoff' => $cutoff,
            ]
        );

        $cleaned = $this->db->affectedRows();

        if ($cleaned > 0) {
            Logger::info('Stale jobs cleaned', ['count' => $cleaned]);
        }

        return $cleaned;
    }

    /**
     * Clean up old completed/failed jobs (older than 24 hours)
     * Called by daily_cleanup.php
     * 
     * @return int Number of jobs deleted
     */
    public function cleanupOldJobs(): int
    {
        $cutoff = time() - (24 * 3600);

        $this->db->execute(
            "DELETE FROM active_jobs WHERE queue_id IN 
             (SELECT id FROM pending_queue WHERE completed_at < :cutoff AND status IN ('completed', 'failed'))",
            ['cutoff' => $cutoff]
        );

        $result = $this->db->execute(
            "DELETE FROM pending_queue WHERE completed_at < :cutoff AND status IN ('completed', 'failed')",
            ['cutoff' => $cutoff]
        );

        $deleted = $this->db->affectedRows();

        if ($deleted > 0) {
            Logger::info('Old jobs cleaned', ['deleted' => $deleted]);
        }

        return $deleted;
    }

    /**
     * ============================================================
     * Statistics
     * ============================================================
     */

    /**
     * Get comprehensive queue statistics
     * 
     * @return array<string, mixed> Queue statistics
     */
    public function getStats(): array
    {
        return [
            'queue_size'        => $this->getQueueSize(),
            'pending'           => $this->db->fetchValue("SELECT COUNT(*) FROM pending_queue WHERE status='pending'"),
            'dispatched'        => $this->db->fetchValue("SELECT COUNT(*) FROM pending_queue WHERE status='dispatched'"),
            'completed_today'   => $this->db->fetchValue(
                "SELECT COUNT(*) FROM pending_queue WHERE status='completed' AND date(completed_at, 'unixepoch') = date('now')"
            ),
            'failed_today'      => $this->db->fetchValue(
                "SELECT COUNT(*) FROM pending_queue WHERE status='failed' AND date(completed_at, 'unixepoch') = date('now')"
            ),
            'avg_wait_seconds'  => $this->estimateWaitTime($this->getQueueSize()),
            'github_remaining'  => $this->github->getRemainingCalls(),
            'github_safe'       => $this->github->hasRateLimitRemaining(),
            'active_github_jobs'=> $this->github->countActiveJobs(),
        ];
    }
}
