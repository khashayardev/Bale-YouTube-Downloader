<?php
/**
 * ============================================================
 * status_checker.php — Job Status API Endpoint
 * ============================================================
 * 
 * 🏗️ Architecture Layer: 3 - Entry Point (API)
 * 📦 Part of: Khashayar YouTube Downloader - Queue System
 * 🔗 Dependency: QueueManager (File 7) + all underlying layers
 * 
 * 🎯 Purpose:
 *   • Dedicated endpoint for checking job status
 *   • Called by Bale callback when user clicks "بررسی وضعیت"
 *   • Returns queue position, estimated wait time, and job state
 *   • Can be called via HTTP for external monitoring
 *   • Lightweight: no message processing, just status lookup
 * 
 * 🔌 Integration:
 *   • Called from gateway.php when callback_data = 'check_status'
 *   • Can also be called directly for monitoring dashboards
 *   • Returns JSON for API calls, text for Bale integration
 * 
 * 🔒 Security:
 *   • Validates chat_id format
 *   • Rate limited for status checks (3 minute window)
 *   • No sensitive data exposed in responses
 * 
 * @package     KhashayarDownloader
 * @version     5.0.0
 * @author      Khashayar
 * @license     MIT
 * @since       2026-05-16
 */

declare(strict_types=1);

// ══════════════════════════════════════════════════════════
// Bootstrap
// ══════════════════════════════════════════════════════════

define('APP_RUNNING', true);

require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Logger.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/RateLimiter.php';
require_once __DIR__ . '/lib/BaleNotifier.php';
require_once __DIR__ . '/lib/GitHubClient.php';
require_once __DIR__ . '/lib/QueueManager.php';

// ══════════════════════════════════════════════════════════
// Status Checker Class
// ══════════════════════════════════════════════════════════

class StatusChecker
{
    /** @var QueueManager Queue manager instance */
    private QueueManager $queueManager;

    /** @var RateLimiter Rate limiter instance */
    private RateLimiter $rateLimiter;

    /** @var Database Database instance */
    private Database $db;

    /** @var array<string, string> Status display map (Persian) */
    private const STATUS_DISPLAY = [
        'pending'    => '⏳ در صف انتظار',
        'dispatched' => '🔄 در حال دانلود',
        'completed'  => '✅ کامل شده',
        'failed'     => '❌ ناموفق',
        'unknown'    => '⏳ نامشخص',
    ];

    /** @var array<string, string> Status emoji map */
    private const STATUS_EMOJI = [
        'pending'    => '⏳',
        'dispatched' => '🔄',
        'completed'  => '✅',
        'failed'     => '❌',
        'unknown'    => '❓',
    ];

    /**
     * Constructor
     * 
     * @param QueueManager|null $queueManager Queue manager
     * @param RateLimiter|null $rateLimiter Rate limiter
     * @param Database|null $db Database
     */
    public function __construct(
        ?QueueManager $queueManager = null,
        ?RateLimiter $rateLimiter = null,
        ?Database $db = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->rateLimiter = $rateLimiter ?? new RateLimiter($this->db);
        $this->queueManager = $queueManager ?? new QueueManager(
            $this->db,
            new GitHubClient(),
            $this->rateLimiter
        );
    }

    /**
     * ============================================================
     * Status Methods
     * ============================================================
     */

    /**
     * Get comprehensive status for a user
     * 
     * @param string $chatId User's chat ID
     * @return array{has_job: bool, job: array|null, queue_info: array, rate_info: array}
     */
    public function getUserStatus(string $chatId): array
    {
        // Get user's latest job
        $job = $this->queueManager->getUserJobStatus($chatId);

        // Get queue information
        $queueSize = $this->queueManager->getQueueSize();
        $userPendingCount = $this->queueManager->getUserPendingCount($chatId);

        // Get rate limit information
        $remainingTime = $this->rateLimiter->getRemainingTime($chatId);
        $dailyRemaining = $this->rateLimiter->getRemainingDailyRequests($chatId);

        // Build job details
        $jobDetails = null;
        if ($job) {
            $position = 0;
            $estimatedWait = 0;

            if ($job['status'] === 'pending') {
                $position = $this->queueManager->getQueuePosition((int) $job['id']);
                $estimatedWait = $this->queueManager->estimateWaitTime($position);
            }

            $jobDetails = [
                'id'              => $job['id'],
                'status'          => $job['status'],
                'status_display'  => self::STATUS_DISPLAY[$job['status']] ?? self::STATUS_DISPLAY['unknown'],
                'status_emoji'    => self::STATUS_EMOJI[$job['status']] ?? self::STATUS_EMOJI['unknown'],
                'youtube_url'     => $job['youtube_url'] ?? '',
                'quality'         => $job['quality'] ?? 'best',
                'quality_display' => QUALITY_MAP[$job['quality']] ?? '✨ Best Quality',
                'position'        => $position,
                'estimated_wait'  => $estimatedWait,
                'estimated_wait_display' => $this->formatWaitTime($estimatedWait),
                'created_at'      => $job['created_at'] ?? 0,
                'created_at_display' => $job['created_at'] ? date('H:i:s', (int) $job['created_at']) : '',
                'completed_at'    => $job['completed_at'] ?? null,
                'retry_count'     => $job['retry_count'] ?? 0,
                'error_message'   => $job['error_message'] ?? null,
            ];
        }

        return [
            'has_job'    => $job !== null,
            'job'        => $jobDetails,
            'queue_info' => [
                'total_in_queue'    => $queueSize,
                'user_pending'      => $userPendingCount,
                'max_per_user'      => defined('QUEUE_MAX_PER_USER') ? QUEUE_MAX_PER_USER : 3,
            ],
            'rate_info'  => [
                'next_request_seconds' => $remainingTime,
                'next_request_display' => $this->formatWaitTime($remainingTime),
                'daily_remaining'      => $dailyRemaining,
                'daily_limit'          => defined('DAILY_LIMIT_PER_USER') ? DAILY_LIMIT_PER_USER : 50,
                'can_request'          => $remainingTime <= 0 && $dailyRemaining > 0,
            ],
        ];
    }

    /**
     * Get queue statistics (for monitoring)
     * 
     * @return array<string, mixed> Queue statistics
     */
    public function getQueueStats(): array
    {
        return $this->queueManager->getStats();
    }

    /**
     * Get system health status
     * 
     * @return array<string, mixed> Health check data
     */
    public function getHealthStatus(): array
    {
        $dbStats = $this->db->getStats();
        $queueStats = $this->queueManager->getStats();
        $rateStats = $this->rateLimiter->getStats();

        $isHealthy = true;
        $warnings = [];

        // Check queue size
        $warningSize = defined('QUEUE_WARNING_SIZE') ? QUEUE_WARNING_SIZE : 500;
        if ($queueStats['queue_size'] > $warningSize) {
            $warnings[] = "Queue size ({$queueStats['queue_size']}) exceeds warning threshold ({$warningSize})";
        }

        // Check database size
        if ($dbStats['file_size_mb'] > 100) {
            $warnings[] = "Database size ({$dbStats['file_size_mb']}MB) is large — consider VACUUM";
        }

        // Check rate limited users
        if ($rateStats['users_rate_limited'] > 100) {
            $warnings[] = "High number of rate-limited users ({$rateStats['users_rate_limited']})";
        }

        if (!empty($warnings)) {
            $isHealthy = false;
        }

        return [
            'healthy'   => $isHealthy,
            'warnings'  => $warnings,
            'database'  => $dbStats,
            'queue'     => $queueStats,
            'rate'      => $rateStats,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * ============================================================
     * Response Formatting
     * ============================================================
     */

    /**
     * Format status as HTML for monitoring dashboard
     * 
     * @param string $chatId User's chat ID (optional)
     * @return string HTML output
     */
    public function renderHtml(string $chatId = ''): string
    {
        $health = $this->getHealthStatus();
        
        $html = '<!DOCTYPE html><html dir="rtl" lang="fa"><head><meta charset="UTF-8">';
        $html .= '<title>وضعیت سیستم</title>';
        $html .= '<style>
            body { font-family: Tahoma, sans-serif; margin: 20px; background: #f5f5f5; }
            .card { background: white; border-radius: 8px; padding: 20px; margin: 10px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            .healthy { border-right: 4px solid #4caf50; }
            .warning { border-right: 4px solid #ff9800; }
            .error { border-right: 4px solid #f44336; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 8px 12px; text-align: right; border-bottom: 1px solid #eee; }
            th { background: #f5f5f5; }
            .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; }
            .badge-success { background: #e8f5e9; color: #2e7d32; }
            .badge-warning { background: #fff3e0; color: #e65100; }
            .badge-error { background: #ffebee; color: #c62828; }
        </style></head><body>';
        
        $html .= '<h1>📊 وضعیت سیستم — Khashayar Downloader v5.0</h1>';
        
        // Health status
        $healthClass = $health['healthy'] ? 'healthy' : 'warning';
        $healthBadge = $health['healthy'] 
            ? '<span class="badge badge-success">✅ سالم</span>' 
            : '<span class="badge badge-warning">⚠️ نیاز به توجه</span>';
        
        $html .= "<div class='card {$healthClass}'>";
        $html .= "<h2>وضعیت کلی {$healthBadge}</h2>";
        
        if (!empty($health['warnings'])) {
            $html .= '<ul>';
            foreach ($health['warnings'] as $warning) {
                $html .= "<li>⚠️ {$warning}</li>";
            }
            $html .= '</ul>';
        }
        $html .= '</div>';
        
        // Queue stats
        $html .= '<div class="card">';
        $html .= '<h2>📋 آمار صف</h2>';
        $html .= '<table>';
        $html .= '<tr><th>شاخص</th><th>مقدار</th></tr>';
        $html .= '<tr><td>کارهای در صف</td><td>' . ($health['queue']['queue_size'] ?? 0) . '</td></tr>';
        $html .= '<tr><td>در انتظار</td><td>' . ($health['queue']['pending'] ?? 0) . '</td></tr>';
        $html .= '<tr><td>در حال اجرا</td><td>' . ($health['queue']['dispatched'] ?? 0) . '</td></tr>';
        $html .= '<tr><td>کامل شده امروز</td><td>' . ($health['queue']['completed_today'] ?? 0) . '</td></tr>';
        $html .= '<tr><td>ناموفق امروز</td><td>' . ($health['queue']['failed_today'] ?? 0) . '</td></tr>';
        $html .= '<tr><td>GitHub API باقی‌مانده</td><td>' . ($health['queue']['github_remaining'] ?? 0) . '</td></tr>';
        $html .= '</table>';
        $html .= '</div>';
        
        // Database stats
        $html .= '<div class="card">';
        $html .= '<h2>💾 وضعیت دیتابیس</h2>';
        $html .= '<table>';
        $html .= '<tr><th>شاخص</th><th>مقدار</th></tr>';
        $html .= '<tr><td>حجم فایل</td><td>' . ($health['database']['file_size_mb'] ?? 0) . ' MB</td></tr>';
        $html .= '<tr><td>WAL حجم</td><td>' . ($health['database']['wal_size_mb'] ?? 0) . ' MB</td></tr>';
        $html .= '<tr><td>کل کاربران</td><td>' . ($health['database']['total_users'] ?? 0) . '</td></tr>';
        $html .= '</table>';
        $html .= '</div>';
        
        $html .= '<p style="text-align:center;color:#999;">بروزرسانی: ' . $health['timestamp'] . '</p>';
        $html .= '</body></html>';
        
        return $html;
    }

    /**
     * Format status as JSON for API
     * 
     * @param string $chatId User's chat ID
     * @return string JSON response
     */
    public function renderJson(string $chatId): string
    {
        $status = $this->getUserStatus($chatId);
        $status['timestamp'] = date('Y-m-d H:i:s');
        
        return json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Format status as text message for Bale
     * 
     * @param string $chatId User's chat ID
     * @return string Formatted text message
     */
    public function renderTextMessage(string $chatId): string
    {
        $status = $this->getUserStatus($chatId);

        if (!$status['has_job']) {
            $text = "📋 *شما درخواست فعالی ندارید.*\n\n";
            $text .= "💡 برای دانلود جدید، لینک یوتیوب را ارسال کنید.\n\n";
            $text .= "⏱ درخواست بعدی: {$status['rate_info']['next_request_display']}\n";
            $text .= "📥 دانلودهای باقی‌مانده امروز: {$status['rate_info']['daily_remaining']}";
            return $text;
        }

        $job = $status['job'];
        $text = "📊 *وضعیت درخواست شما*\n\n";
        $text .= "{$job['status_emoji']} *وضعیت:* {$job['status_display']}\n";
        $text .= "🎬 *کیفیت:* {$job['quality_display']}\n";

        if ($job['status'] === 'pending') {
            $text .= "🔢 *موقعیت در صف:* {$job['position']}\n";
            $text .= "⏱ *زمان تقریبی:* {$job['estimated_wait_display']}\n";
        }

        if ($job['status'] === 'dispatched') {
            $text .= "🔄 *در حال پردازش توسط سرور...*\n";
            $text .= "⏱ معمولاً ۲ تا ۵ دقیقه طول می‌کشد.\n";
        }

        if ($job['status'] === 'completed') {
            $text .= "✅ *دانلود کامل شده است!*\n";
            $text .= "📁 فایل باید برای شما ارسال شده باشد.\n";
        }

        if ($job['status'] === 'failed') {
            $text .= "❌ *دانلود ناموفق بود*\n";
            if ($job['error_message']) {
                $text .= "⚠️ خطا: {$job['error_message']}\n";
            }
            $text .= "🔄 لطفاً دوباره تلاش کنید.\n";
        }

        $text .= "\n📊 *وضعیت کلی:*\n";
        $text .= "📋 کارهای در صف: {$status['queue_info']['total_in_queue']}\n";
        $text .= "⏱ درخواست بعدی شما: {$status['rate_info']['next_request_display']}";

        return $text;
    }

    /**
     * ============================================================
     * Utility Methods
     * ============================================================
     */

    /**
     * Format wait time in Persian
     * 
     * @param int $seconds Seconds to format
     * @return string Formatted string
     */
    private function formatWaitTime(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'آماده ✅';
        }

        if ($seconds < 60) {
            return "{$seconds} ثانیه";
        }

        $minutes = ceil($seconds / 60);

        if ($minutes < 60) {
            return "حدود {$minutes} دقیقه";
        }

        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($mins > 0) {
            return "حدود {$hours} ساعت و {$mins} دقیقه";
        }

        return "حدود {$hours} ساعت";
    }
}

// ══════════════════════════════════════════════════════════
// Request Handling
// ══════════════════════════════════════════════════════════

/**
 * Handle incoming request
 * Supports: CLI, HTTP GET (HTML), HTTP GET with ?chat_id= (JSON), HTTP GET with ?format=json
 */
function handleRequest(): void
{
    $checker = new StatusChecker();

    // CLI mode — output text
    if (php_sapi_name() === 'cli') {
        $chatId = $argv[1] ?? '';
        
        if (!empty($chatId)) {
            $status = $checker->getUserStatus($chatId);
            echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        } else {
            $health = $checker->getHealthStatus();
            echo json_encode($health, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        }
        return;
    }

    // HTTP mode
    $chatId = $_GET['chat_id'] ?? '';
    $format = $_GET['format'] ?? 'html';

    // If chat_id provided, return JSON
    if (!empty($chatId)) {
        header('Content-Type: application/json; charset=utf-8');
        echo $checker->renderJson($chatId);
        return;
    }

    // If format=json, return health JSON
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($checker->getHealthStatus(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return;
    }

    // Default: HTML dashboard
    header('Content-Type: text/html; charset=utf-8');
    echo $checker->renderHtml();
}

// ══════════════════════════════════════════════════════════
// Execute
// ══════════════════════════════════════════════════════════

try {
    handleRequest();
} catch (\Exception $e) {
    Logger::exception($e, 'Status checker error');
    
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error'   => 'Internal server error',
            'message' => isDevelopment() ? $e->getMessage() : 'Check logs for details',
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo "Error: {$e->getMessage()}\n";
    }
}
