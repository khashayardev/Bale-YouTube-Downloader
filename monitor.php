<?php
/**
 * ============================================================
 * monitor.php — System Monitoring Dashboard
 * ============================================================
 * 
 * 🏗️ Architecture Layer: 4 - Utilities (Optional Dashboard)
 * 📦 Part of: Khashayar YouTube Downloader - Queue System
 * 🔗 Dependency: QueueManager, StatusChecker
 * 
 * 🎯 Purpose:
 *   • Real-time system monitoring dashboard
 *   • Queue status and statistics visualization
 *   • GitHub API rate limit tracking
 *   • Recent job history and failure rates
 *   • Health check with automatic warnings
 *   • Responsive design for mobile and desktop
 *   • Auto-refresh every 30 seconds
 * 
 * 🔒 Security:
 *   • Protected by .htaccess (basic auth recommended)
 *   • No sensitive data exposed (tokens redacted)
 *   • Read-only: no actions can be performed
 *   • Chat IDs hashed in display
 * 
 * 🎨 Design:
 *   • RTL Persian interface
 *   • CSS-only responsive design
 *   • No external dependencies (pure PHP + HTML + CSS)
 *   • Color-coded status indicators
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
require_once __DIR__ . '/status_checker.php';

// ══════════════════════════════════════════════════════════
// Initialize Services
// ══════════════════════════════════════════════════════════

$db = Database::getInstance();
$rateLimiter = new RateLimiter($db);
$githubClient = new GitHubClient();
$queueManager = new QueueManager($db, $githubClient, $rateLimiter);
$statusChecker = new StatusChecker($queueManager, $rateLimiter, $db);

// Refresh rate limit info
$githubClient->fetchRateLimit();

// Get all stats
$health = $statusChecker->getHealthStatus();
$queueStats = $queueManager->getStats();
$rateLimitStatus = $githubClient->getRateLimitStatus();
$dbStats = $db->getStats();

// ══════════════════════════════════════════════════════════
// Helper Functions
// ══════════════════════════════════════════════════════════

/**
 * Get CSS class for status badge
 * 
 * @param string $status Status value
 * @return string CSS class name
 */
function getStatusBadgeClass(string $status): string
{
    return match ($status) {
        'completed' => 'badge-success',
        'pending'   => 'badge-warning',
        'dispatched'=> 'badge-info',
        'failed'    => 'badge-error',
        default     => 'badge-neutral',
    };
}

/**
 * Get Persian status text
 * 
 * @param string $status Status value
 * @return string Persian status
 */
function getStatusText(string $status): string
{
    return match ($status) {
        'completed'  => '✅ کامل',
        'pending'    => '⏳ در صف',
        'dispatched' => '🔄 در حال اجرا',
        'failed'     => '❌ ناموفق',
        default      => '❓ نامشخص',
    };
}

/**
 * Get health status CSS class
 * 
 * @param bool $healthy Health status
 * @return string CSS class
 */
function getHealthClass(bool $healthy): string
{
    return $healthy ? 'status-healthy' : 'status-warning';
}

/**
 * Get health status text
 * 
 * @param bool $healthy Health status
 * @return string Status text
 */
function getHealthText(bool $healthy): string
{
    return $healthy ? '✅ سیستم سالم' : '⚠️ نیاز به بررسی';
}

/**
 * Hash chat ID for display
 * 
 * @param string $chatId Chat ID
 * @return string Hashed ID
 */
function hashChatId(string $chatId): string
{
    return substr(hash('sha256', $chatId), 0, 10) . '...';
}

// Get recent jobs for history table
$recentJobs = $db->fetchAll(
    "SELECT id, chat_id, youtube_url, quality, status, created_at, completed_at
     FROM pending_queue 
     ORDER BY created_at DESC 
     LIMIT 20"
);

// Calculate rates
$totalToday = ($queueStats['completed_today'] ?? 0) + ($queueStats['failed_today'] ?? 0);
$successRate = $totalToday > 0 
    ? round((($queueStats['completed_today'] ?? 0) / $totalToday) * 100, 1) 
    : 100;

// ══════════════════════════════════════════════════════════
// Render Dashboard
// ══════════════════════════════════════════════════════════

// Set headers
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Refresh: 30'); // Auto-refresh every 30 seconds

?>
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📊 مانیتورینگ — <?= htmlspecialchars(PROJECT_NAME) ?></title>
    <style>
        /* ═══════════════════════════════════════════════════ */
        /* Base Styles                                         */
        /* ═══════════════════════════════════════════════════ */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: Tahoma, 'Segoe UI', sans-serif;
            background: #0f1923;
            color: #e0e0e0;
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* ═══════════════════════════════════════════════════ */
        /* Header                                              */
        /* ═══════════════════════════════════════════════════ */
        .header {
            background: linear-gradient(135deg, #1a2a3a 0%, #0d1b2a 100%);
            border: 1px solid #2a3a4a;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .header h1 {
            font-size: 24px;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .header-info {
            text-align: left;
            font-size: 13px;
            color: #8899aa;
        }
        
        .health-indicator {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: bold;
        }
        
        .status-healthy { background: #1a3a1a; color: #4caf50; border: 1px solid #2e7d32; }
        .status-warning { background: #3a2a1a; color: #ff9800; border: 1px solid #e65100; }
        
        .refresh-badge {
            background: #1a2a3a;
            color: #64b5f6;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            border: 1px solid #2a4a6a;
        }
        
        /* ═══════════════════════════════════════════════════ */
        /* Stats Grid                                          */
        /* ═══════════════════════════════════════════════════ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: #1a2a3a;
            border: 1px solid #2a3a4a;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
        }
        
        .stat-value {
            font-size: 36px;
            font-weight: bold;
            color: #fff;
            margin: 8px 0;
        }
        
        .stat-label {
            font-size: 13px;
            color: #8899aa;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-icon {
            font-size: 28px;
            margin-bottom: 4px;
        }
        
        /* Color variants */
        .stat-success .stat-value { color: #4caf50; }
        .stat-warning .stat-value { color: #ff9800; }
        .stat-info .stat-value { color: #64b5f6; }
        .stat-error .stat-value { color: #f44336; }
        .stat-neutral .stat-value { color: #b0bec5; }
        
        /* ═══════════════════════════════════════════════════ */
        /* Panels                                              */
        /* ═══════════════════════════════════════════════════ */
        .panel-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .panel {
            background: #1a2a3a;
            border: 1px solid #2a3a4a;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .panel-header {
            background: #1a2a4a;
            padding: 14px 20px;
            border-bottom: 1px solid #2a3a4a;
            font-size: 15px;
            font-weight: bold;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .panel-body {
            padding: 20px;
        }
        
        .full-width {
            grid-column: 1 / -1;
        }
        
        /* ═══════════════════════════════════════════════════ */
        /* Tables                                              */
        /* ═══════════════════════════════════════════════════ */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 10px 14px;
            text-align: right;
            border-bottom: 1px solid #2a3a4a;
        }
        
        th {
            background: #1a2a4a;
            color: #8899aa;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        td {
            font-size: 13px;
        }
        
        tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }
        
        /* ═══════════════════════════════════════════════════ */
        /* Badges                                              */
        /* ═══════════════════════════════════════════════════ */
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .badge-success { background: #1a3a1a; color: #4caf50; }
        .badge-warning { background: #3a2a1a; color: #ff9800; }
        .badge-info { background: #1a2a3a; color: #64b5f6; }
        .badge-error { background: #3a1a1a; color: #f44336; }
        .badge-neutral { background: #2a2a3a; color: #b0bec5; }
        
        /* ═══════════════════════════════════════════════════ */
        /* Progress Bar                                        */
        /* ═══════════════════════════════════════════════════ */
        .progress-bar {
            background: #0d1b2a;
            border-radius: 8px;
            height: 8px;
            overflow: hidden;
            margin: 8px 0;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 8px;
            transition: width 0.3s ease;
        }
        
        .progress-success { background: linear-gradient(90deg, #2e7d32, #4caf50); }
        .progress-warning { background: linear-gradient(90deg, #e65100, #ff9800); }
        .progress-error { background: linear-gradient(90deg, #b71c1c, #f44336); }
        .progress-info { background: linear-gradient(90deg, #1565c0, #64b5f6); }
        
        /* ═══════════════════════════════════════════════════ */
        /* Alerts                                              */
        /* ═══════════════════════════════════════════════════ */
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 12px;
            font-size: 13px;
        }
        
        .alert-warning { background: #3a2a1a; border: 1px solid #e65100; color: #ffcc80; }
        .alert-error { background: #3a1a1a; border: 1px solid #b71c1c; color: #ef9a9a; }
        
        /* ═══════════════════════════════════════════════════ */
        /* Responsive                                          */
        /* ═══════════════════════════════════════════════════ */
        @media (max-width: 768px) {
            .container { padding: 10px; }
            .header { flex-direction: column; text-align: center; }
            .header-info { text-align: center; }
            .stats-grid { grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); }
            .panel-grid { grid-template-columns: 1fr; }
            .stat-value { font-size: 24px; }
            th, td { padding: 8px 6px; font-size: 11px; }
        }
        
        /* ═══════════════════════════════════════════════════ */
        /* Footer                                              */
        /* ═══════════════════════════════════════════════════ */
        .footer {
            text-align: center;
            padding: 20px;
            color: #556677;
            font-size: 12px;
        }
        
        .footer a {
            color: #64b5f6;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        
        <!-- ═══════════════════════════════════════════════ -->
        <!-- Header                                           -->
        <!-- ═══════════════════════════════════════════════ -->
        <div class="header">
            <h1>
                📊 <?= htmlspecialchars(PROJECT_NAME) ?>
                <span class="health-indicator <?= getHealthClass($health['healthy']) ?>">
                    <?= getHealthText($health['healthy']) ?>
                </span>
            </h1>
            <div class="header-info">
                <div>⏱ بروزرسانی: <?= date('H:i:s') ?></div>
                <div><span class="refresh-badge">🔄 بروزرسانی خودکار هر ۳۰ ثانیه</span></div>
            </div>
        </div>
        
        <!-- ═══════════════════════════════════════════════ -->
        <!-- Warnings                                         -->
        <!-- ═══════════════════════════════════════════════ -->
        <?php if (!empty($health['warnings'])): ?>
            <?php foreach ($health['warnings'] as $warning): ?>
                <div class="alert alert-warning">⚠️ <?= htmlspecialchars($warning) ?></div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- ═══════════════════════════════════════════════ -->
        <!-- Stats Cards                                      -->
        <!-- ═══════════════════════════════════════════════ -->
        <div class="stats-grid">
            <div class="stat-card stat-warning">
                <div class="stat-icon">📋</div>
                <div class="stat-value"><?= $queueStats['queue_size'] ?? 0 ?></div>
                <div class="stat-label">کار در صف</div>
            </div>
            
            <div class="stat-card stat-info">
                <div class="stat-icon">🔄</div>
                <div class="stat-value"><?= $queueStats['dispatched'] ?? 0 ?></div>
                <div class="stat-label">در حال اجرا</div>
            </div>
            
            <div class="stat-card stat-success">
                <div class="stat-icon">✅</div>
                <div class="stat-value"><?= $queueStats['completed_today'] ?? 0 ?></div>
                <div class="stat-label">کامل شده امروز</div>
            </div>
            
            <div class="stat-card stat-error">
                <div class="stat-icon">❌</div>
                <div class="stat-value"><?= $queueStats['failed_today'] ?? 0 ?></div>
                <div class="stat-label">ناموفق امروز</div>
            </div>
            
            <div class="stat-card <?= $rateLimitStatus['safe'] ? 'stat-success' : 'stat-warning' ?>">
                <div class="stat-icon">🔌</div>
                <div class="stat-value"><?= $rateLimitStatus['remaining'] ?></div>
                <div class="stat-label">GitHub API باقی‌مانده</div>
            </div>
            
            <div class="stat-card stat-neutral">
                <div class="stat-icon">📊</div>
                <div class="stat-value"><?= $successRate ?>%</div>
                <div class="stat-label">نرخ موفقیت امروز</div>
            </div>
        </div>
        
        <!-- ═══════════════════════════════════════════════ -->
        <!-- Panels                                           -->
        <!-- ═══════════════════════════════════════════════ -->
        <div class="panel-grid">
            
            <!-- Queue Status Panel -->
            <div class="panel">
                <div class="panel-header">📋 وضعیت صف</div>
                <div class="panel-body">
                    <table>
                        <tr><td>📋 کل کارهای در صف</td><td><strong><?= $queueStats['queue_size'] ?? 0 ?></strong></td></tr>
                        <tr><td>⏳ در انتظار</td><td><strong><?= $queueStats['pending'] ?? 0 ?></strong></td></tr>
                        <tr><td>🔄 در حال اجرا</td><td><strong><?= $queueStats['dispatched'] ?? 0 ?></strong></td></tr>
                        <tr><td>✅ کامل شده امروز</td><td><strong><?= $queueStats['completed_today'] ?? 0 ?></strong></td></tr>
                        <tr><td>❌ ناموفق امروز</td><td><strong><?= $queueStats['failed_today'] ?? 0 ?></strong></td></tr>
                        <tr><td>⏱ میانگین انتظار</td><td><strong><?= ceil(($queueStats['avg_wait_seconds'] ?? 0) / 60) ?> دقیقه</strong></td></tr>
                    </table>
                    
                    <?php if (($queueStats['queue_size'] ?? 0) > 0): ?>
                        <?php 
                            $maxQueue = defined('QUEUE_MAX_SIZE') ? QUEUE_MAX_SIZE : 2000;
                            $queuePercent = min(100, round((($queueStats['queue_size'] ?? 0) / $maxQueue) * 100));
                            $progressClass = $queuePercent > 80 ? 'progress-error' : ($queuePercent > 50 ? 'progress-warning' : 'progress-info');
                        ?>
                        <div style="margin-top:12px;">
                            <small>ظرفیت صف: <?= $queuePercent ?>%</small>
                            <div class="progress-bar">
                                <div class="progress-fill <?= $progressClass ?>" style="width: <?= $queuePercent ?>%"></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- GitHub Status Panel -->
            <div class="panel">
                <div class="panel-header">🔌 وضعیت GitHub</div>
                <div class="panel-body">
                    <table>
                        <tr><td>📊 API باقی‌مانده</td><td><strong><?= $rateLimitStatus['remaining'] ?></strong></td></tr>
                        <tr><td>🔄 بازنشانی</td><td><strong><?= $rateLimitStatus['reset_time'] ?? 'N/A' ?></strong></td></tr>
                        <tr><td>🛡️ وضعیت امن</td><td>
                            <span class="badge <?= $rateLimitStatus['safe'] ? 'badge-success' : 'badge-error' ?>">
                                <?= $rateLimitStatus['safe'] ? '✅ امن' : '⚠️ نزدیک به محدودیت' ?>
                            </span>
                        </td></tr>
                        <tr><td>⚡ کارهای فعال</td><td><strong><?= $queueStats['active_github_jobs'] ?? 0 ?></strong></td></tr>
                        <tr><td>📦 حداکثر همزمان</td><td><strong><?= defined('MAX_CONCURRENT_GITHUB_JOBS') ? MAX_CONCURRENT_GITHUB_JOBS : 15 ?></strong></td></tr>
                    </table>
                    
                    <?php 
                        $ratePercent = min(100, round((($rateLimitStatus['remaining'] ?? 0) / GITHUB_API_HOURLY_LIMIT) * 100));
                        $rateClass = $ratePercent < 10 ? 'progress-error' : ($ratePercent < 25 ? 'progress-warning' : 'progress-success');
                    ?>
                    <div style="margin-top:12px;">
                        <small>API باقی‌مانده: <?= $ratePercent ?>%</small>
                        <div class="progress-bar">
                            <div class="progress-fill <?= $rateClass ?>" style="width: <?= $ratePercent ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Database Status Panel -->
            <div class="panel">
                <div class="panel-header">💾 وضعیت دیتابیس</div>
                <div class="panel-body">
                    <table>
                        <tr><td>📦 حجم فایل</td><td><strong><?= $dbStats['file_size_mb'] ?? 0 ?> MB</strong></td></tr>
                        <tr><td>📝 WAL حجم</td><td><strong><?= $dbStats['wal_size_mb'] ?? 0 ?> MB</strong></td></tr>
                        <tr><td>👥 کل کاربران</td><td><strong><?= $dbStats['total_users'] ?? 0 ?></strong></td></tr>
                        <tr><td>📋 کارهای pending</td><td><strong><?= $dbStats['pending_jobs'] ?? 0 ?></strong></td></tr>
                        <tr><td>🔄 کارهای active</td><td><strong><?= $dbStats['active_jobs'] ?? 0 ?></strong></td></tr>
                    </table>
                    
                    <?php if (($dbStats['file_size_mb'] ?? 0) > 50): ?>
                        <div class="alert alert-warning" style="margin-top:12px;">
                            ⚠️ حجم دیتابیس بیش از ۵۰MB است. VACUUM توصیه می‌شود.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Rate Limit Stats Panel -->
            <div class="panel">
                <div class="panel-header">⏱ آمار محدودیت نرخ</div>
                <div class="panel-body">
                    <?php $rateStats = $rateLimiter->getStats(); ?>
                    <table>
                        <tr><td>👥 کاربران tracked</td><td><strong><?= $rateStats['total_users_tracked'] ?? 0 ?></strong></td></tr>
                        <tr><td>🚫 محدود شده‌ها</td><td><strong><?= $rateStats['users_rate_limited'] ?? 0 ?></strong></td></tr>
                        <tr><td>📊 بیش از حد روزانه</td><td><strong><?= $rateStats['users_daily_exceeded'] ?? 0 ?></strong></td></tr>
                        <tr><td>⏱ پنجره زمانی</td><td><strong><?= $rateStats['rate_limit_seconds'] ?? 300 ?> ثانیه</strong></td></tr>
                        <tr><td>📥 محدودیت روزانه</td><td><strong><?= $rateStats['daily_limit'] ?? 50 ?> درخواست</strong></td></tr>
                    </table>
                </div>
            </div>
            
            <!-- Recent Jobs Panel (Full Width) -->
            <div class="panel full-width">
                <div class="panel-header">📜 تاریخچه اخیر (۲۰ مورد آخر)</div>
                <div class="panel-body" style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>کاربر</th>
                                <th>لینک</th>
                                <th>کیفیت</th>
                                <th>وضعیت</th>
                                <th>زمان ایجاد</th>
                                <th>زمان تکمیل</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentJobs)): ?>
                                <tr>
                                    <td colspan="7" style="text-align:center;color:#556677;padding:20px;">
                                        📋 هیچ کاری ثبت نشده است.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentJobs as $job): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $job['id']) ?></td>
                                        <td><code><?= hashChatId($job['chat_id']) ?></code></td>
                                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                            <?= htmlspecialchars(substr($job['youtube_url'] ?? '', 0, 40)) ?>...
                                        </td>
                                        <td><?= htmlspecialchars(QUALITY_MAP[$job['quality']] ?? $job['quality'] ?? 'best') ?></td>
                                        <td>
                                            <span class="badge <?= getStatusBadgeClass($job['status']) ?>">
                                                <?= getStatusText($job['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= $job['created_at'] ? date('H:i:s', (int) $job['created_at']) : 'N/A' ?></td>
                                        <td><?= $job['completed_at'] ? date('H:i:s', (int) $job['completed_at']) : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </div>
        
        <!-- ═══════════════════════════════════════════════ -->
        <!-- Footer                                            -->
        <!-- ═══════════════════════════════════════════════ -->
        <div class="footer">
            <p><?= htmlspecialchars(PROJECT_NAME) ?> v5.0 | 
               <a href="<?= htmlspecialchars(PROJECT_URL) ?>" target="_blank"><?= htmlspecialchars(PROJECT_URL) ?></a> |
               ⏱ زمان سرور: <?= date('Y-m-d H:i:s') ?></p>
        </div>
        
    </div>
</body>
</html>
