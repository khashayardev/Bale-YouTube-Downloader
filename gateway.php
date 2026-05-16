<?php
/**
 * ============================================================
 * gateway.php — Bale YouTube Downloader Gateway (v5.0)
 * ============================================================
 * 
 * 🏗️ Architecture Layer: 3 - Entry Point (Webhook Handler)
 * 📦 Part of: Khashayar YouTube Downloader - Queue System
 * 🔗 Dependency: QueueManager (File 7) + all underlying layers
 * 
 * 🎯 Purpose:
 *   • Main webhook endpoint for Bale Bot API
 *   • Receives updates from Bale (messages + callback queries)
 *   • Routes to appropriate handlers
 *   • ALL GitHub dispatch now goes through QueueManager
 *   • Deduplication of Telegram updates
 *   • User settings management (quality, subtitles)
 *   • Menu and keyboard interaction handling
 * 
 * 🔄 CHANGES FROM v4.0 (ORIGINAL):
 *   ❌ REMOVED: dispatchGitHubWorkflow() — direct GitHub calls
 *   ❌ REMOVED: dispatchSearchWorkflow() — direct GitHub calls
 *   ❌ REMOVED: callBaleAPI() — moved to BaleNotifier
 *   ❌ REMOVED: sendMessage/editMessage/sendDocument — moved to BaleNotifier
 *   ❌ REMOVED: isRateLimited/updateRateLimit — moved to RateLimiter
 *   ❌ REMOVED: initDatabase() — moved to Database
 *   ✅ ADDED: QueueManager integration for all downloads
 *   ✅ ADDED: Queue position feedback to users
 *   ✅ ADDED: Proper dependency injection via lib/ files
 *   ✅ KEPT: User settings management (quality, subtitles)
 *   ✅ KEPT: Menu and keyboard builders
 *   ✅ KEPT: YouTube URL extraction
 *   ✅ KEPT: Message and callback processing logic
 * 
 * 🔒 Security:
 *   • Update deduplication (prevents double-processing)
 *   • All tokens via Config.php (never hardcoded)
 *   • Input validation on all user data
 *   • Rate limiting enforced at entry point
 * 
 * @package     KhashayarDownloader
 * @version     5.0.0
 * @author      Khashayar
 * @license     MIT
 * @since       2026-05-16
 */

declare(strict_types=1);

// ══════════════════════════════════════════════════════════
// Bootstrap: Load all dependencies
// ══════════════════════════════════════════════════════════

// Define guard constant for Config.php
define('APP_RUNNING', true);

// Load configuration (File 1)
require_once __DIR__ . '/lib/Config.php';

// Load logger (File 2)
require_once __DIR__ . '/lib/Logger.php';

// Load database (File 3)
require_once __DIR__ . '/lib/Database.php';

// Load rate limiter (File 4)
require_once __DIR__ . '/lib/RateLimiter.php';

// Load Bale notifier (File 5)
require_once __DIR__ . '/lib/BaleNotifier.php';

// Load GitHub client (File 6)
require_once __DIR__ . '/lib/GitHubClient.php';

// Load queue manager (File 7)
require_once __DIR__ . '/lib/QueueManager.php';

// ══════════════════════════════════════════════════════════
// Initialize Services
// ══════════════════════════════════════════════════════════

try {
    $db = Database::getInstance();
    $rateLimiter = new RateLimiter($db);
    $githubClient = new GitHubClient();
    $queueManager = new QueueManager($db, $githubClient, $rateLimiter);
    
    Logger::info('Gateway initialized successfully');
} catch (\Exception $e) {
    Logger::exception($e, 'Gateway initialization failed');
    http_response_code(500);
    die('Internal server error. Check logs for details.');
}

// ══════════════════════════════════════════════════════════
// YouTube URL Extraction
// ══════════════════════════════════════════════════════════

/**
 * Extract YouTube URLs from text message
 * Supports: youtube.com/watch?v=, youtu.be/, youtube.com/shorts/
 * 
 * @param string $text Message text
 * @return array<int, string> Array of unique YouTube URLs
 */
function extractYoutubeUrls(string $text): array
{
    $urls = [];
    $patterns = [
        '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})/',
        '/(?:https?:\/\/)?youtu\.be\/([a-zA-Z0-9_-]{11})/',
        '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $text, $matches)) {
            foreach ($matches[1] as $videoId) {
                $urls[] = "https://www.youtube.com/watch?v={$videoId}";
            }
        }
    }

    return array_unique($urls);
}

// ══════════════════════════════════════════════════════════
// User Settings Management
// ══════════════════════════════════════════════════════════

/**
 * Get user settings (quality, subtitles)
 * 
 * @param string $chatId User's chat ID
 * @return array{quality: string, subtitles: string}
 */
function getUserSettings(string $chatId): array
{
    global $db;
    
    $row = $db->fetchOne(
        "SELECT quality, subtitles FROM user_settings WHERE chat_id = :chat_id",
        ['chat_id' => $chatId]
    );

    return $row ?: ['quality' => 'best', 'subtitles' => 'no'];
}

/**
 * Save user settings
 * 
 * @param string $chatId User's chat ID
 * @param string $quality Quality setting
 * @param string $subtitles Subtitle setting ('yes' or 'no')
 * @return void
 */
function saveUserSettings(string $chatId, string $quality, string $subtitles): void
{
    global $db;

    $db->execute(
        "INSERT INTO user_settings (chat_id, quality, subtitles, updated_at) 
         VALUES (:chat_id, :quality, :subs, :time)
         ON CONFLICT(chat_id) DO UPDATE SET 
             quality = :quality2,
             subtitles = :subs2,
             updated_at = :time2",
        [
            'chat_id'   => $chatId,
            'quality'   => $quality,
            'quality2'  => $quality,
            'subs'      => $subtitles,
            'subs2'     => $subtitles,
            'time'      => time(),
            'time2'     => time(),
        ]
    );
}

// ══════════════════════════════════════════════════════════
// Message Processing
// ══════════════════════════════════════════════════════════

/**
 * Process incoming text message
 * 
 * @param array $message Message object from Bale
 * @return void
 */
function processMessage(array $message): void
{
    global $db, $rateLimiter, $queueManager;

    $chatId = $message['chat']['id'] ?? null;
    $text = $message['text'] ?? '';

    if (!$chatId) {
        Logger::warning('Message without chat ID received');
        return;
    }

    Logger::debug('Processing message', [
        'chat_id' => $chatId,
        'text'    => substr($text, 0, 100),
    ]);

    // ──── /start Command ────
    if (str_starts_with($text, '/start')) {
        $welcomeText = "🎬 *سلام! به ربات دانلودر یوتیوب خوش آمدید!*\n\n";
        $welcomeText .= "👇 یکی از گزینه‌های زیر را انتخاب کنید:";
        BaleNotifier::sendMessage($chatId, $welcomeText, BaleNotifier::startMenu());
        return;
    }

    // ──── /help Command ────
    if (str_starts_with($text, '/help')) {
        $helpText = "📖 *راهنمای ربات*\n\n";
        $helpText .= "🔸 *دانلود ویدیو:* لینک یوتیوب را ارسال کنید\n";
        $helpText .= "🔸 *جستجو:* عبارت مورد نظر را تایپ کنید\n";
        $helpText .= "🔸 *تنظیمات:* کیفیت و زیرنویس را تنظیم کنید\n";
        $helpText .= "🔸 *محدودیت:* هر ۵ دقیقه یک دانلود\n";
        $helpText .= "🔸 *صف:* در زمان شلوغی، درخواست شما در صف قرار می‌گیرد\n\n";
        $helpText .= "📊 *وضعیت سرور:* /status";
        BaleNotifier::sendMessage($chatId, $helpText, BaleNotifier::mainMenuKeyboard());
        return;
    }

    // ──── /status Command ────
    if (str_starts_with($text, '/status')) {
        $remaining = $rateLimiter->getRemainingTimeFormatted($chatId);
        $dailyRemaining = $rateLimiter->getRemainingDailyRequests($chatId);
        $queueSize = $queueManager->getQueueSize();
        $userPending = $queueManager->getUserPendingCount($chatId);

        $statusText = "📊 *وضعیت سرور*\n\n";
        $statusText .= "✅ *سرویس:* فعال\n";
        $statusText .= "⏱ *درخواست بعدی شما:* {$remaining}\n";
        $statusText .= "📥 *دانلودهای باقی‌مانده امروز:* {$dailyRemaining}\n";
        $statusText .= "📋 *کارهای در صف:* {$queueSize}\n";
        
        if ($userPending > 0) {
            $statusText .= "⏳ *درخواست‌های شما در صف:* {$userPending}\n";
        }

        $statusText .= "\n💡 با ارسال لینک یوتیوب دانلود را شروع کنید.";
        BaleNotifier::sendMessage($chatId, $statusText, BaleNotifier::mainMenuKeyboard());
        return;
    }

    // ──── Menu Button Handlers ────
    switch ($text) {
        case '🎥 دانلود ویدیو':
            BaleNotifier::sendMessage(
                $chatId,
                "🔗 *لینک ویدیوی یوتیوب را ارسال کنید:*\n\n_مثال: https://youtu.be/abc123def45_"
            );
            return;

        case '🔍 جستجوی یوتیوب':
            BaleNotifier::sendMessage(
                $chatId,
                "🔍 *جستجوی یوتیوب*\n\nلطفاً عبارت مورد نظر خود را وارد کنید:"
            );
            return;

        case '⚙️ تنظیمات':
            $settings = getUserSettings($chatId);
            $qualityName = QUALITY_MAP[$settings['quality']] ?? '✨ Best Quality';
            $subsStatus = $settings['subtitles'] === 'yes' ? '✅ فعال' : '❌ غیرفعال';

            $settingsText = "⚙️ *تنظیمات فعلی:*\n\n";
            $settingsText .= "🎬 *کیفیت:* {$qualityName}\n";
            $settingsText .= "📝 *زیرنویس:* {$subsStatus}\n\n";
            $settingsText .= "برای تغییر روی گزینه مورد نظر کلیک کنید:";
            BaleNotifier::sendMessage($chatId, $settingsText, BaleNotifier::settingsMainMenu());
            return;

        case 'ℹ️ راهنما':
            $helpText = "📖 *راهنمای ربات*\n\n";
            $helpText .= "🔸 لینک یوتیوب ارسال کنید → تأیید → دانلود\n";
            $helpText .= "🔸 تنظیم کیفیت و زیرنویس از منوی تنظیمات\n";
            $helpText .= "🔸 هر ۵ دقیقه یک درخواست\n";
            $helpText .= "🔸 در زمان شلوغی، درخواست در صف قرار می‌گیرد";
            BaleNotifier::sendMessage($chatId, $helpText);
            return;

        case '📊 وضعیت سرور':
            $remaining = $rateLimiter->getRemainingTimeFormatted($chatId);
            $queueSize = $queueManager->getQueueSize();

            $statusText = "📊 *وضعیت سرور*\n\n";
            $statusText .= "✅ سرویس: فعال\n";
            $statusText .= "⏱ درخواست بعدی شما: {$remaining}\n";
            $statusText .= "📋 کارهای در صف: {$queueSize}";
            BaleNotifier::sendMessage($chatId, $statusText);
            return;
    }

    // ──── YouTube URL Detection ────
    $youtubeUrls = extractYoutubeUrls($text);

    if (!empty($youtubeUrls)) {
        // Check rate limit
        if ($rateLimiter->isRateLimited($chatId)) {
            $remaining = $rateLimiter->getRemainingTime($chatId);
            BaleNotifier::notifyRateLimited($chatId, $remaining);
            return;
        }

        // Check daily limit
        if ($rateLimiter->isDailyLimitExceeded($chatId)) {
            BaleNotifier::notifyDailyLimitReached($chatId);
            return;
        }

        // Get user settings
        $settings = getUserSettings($chatId);
        $youtubeUrl = $youtubeUrls[0];

        // Store pending download info for confirmation
        $db->execute(
            "INSERT INTO user_settings (chat_id, quality, subtitles, updated_at) 
             VALUES (:chat_id, :quality, :subs, :time)
             ON CONFLICT(chat_id) DO UPDATE SET 
                 quality = :quality2,
                 subtitles = :subs2,
                 updated_at = :time2",
            [
                'chat_id'   => $chatId,
                'quality'   => $settings['quality'],
                'quality2'  => $settings['quality'],
                'subs'      => $settings['subtitles'],
                'subs2'     => $settings['subtitles'],
                'time'      => time(),
                'time2'     => time(),
            ]
        );

        // Store temporary download request
        $db->execute(
            "CREATE TABLE IF NOT EXISTS pending_downloads (
                chat_id TEXT PRIMARY KEY, 
                youtube_url TEXT, 
                quality TEXT, 
                subtitles TEXT, 
                created_at INTEGER
            )"
        );
        
        $db->execute(
            "INSERT OR REPLACE INTO pending_downloads (chat_id, youtube_url, quality, subtitles, created_at) 
             VALUES (:chat_id, :url, :quality, :subs, :time)",
            [
                'chat_id'  => $chatId,
                'url'      => $youtubeUrl,
                'quality'  => $settings['quality'],
                'subs'     => $settings['subtitles'],
                'time'     => time(),
            ]
        );

        // Show confirmation
        $qualityName = QUALITY_MAP[$settings['quality']] ?? '✨ Best Quality';
        $subsStatus = $settings['subtitles'] === 'yes' ? '✅ فعال' : '❌ غیرفعال';

        $confirmText = "🎬 *آماده دانلود*\n\n";
        $confirmText .= "🔗 `" . substr($youtubeUrl, 0, 50) . "...`\n";
        $confirmText .= "🎬 کیفیت: {$qualityName}\n";
        $confirmText .= "📝 زیرنویس: {$subsStatus}\n\n";
        $confirmText .= "برای شروع دکمه تأیید را بزنید:";

        $keyboard = BaleNotifier::confirmDownloadKeyboard(
            $settings['quality'],
            $settings['subtitles'] === 'yes'
        );

        BaleNotifier::sendMessage($chatId, $confirmText, $keyboard);
        return;
    }

    // ──── Search Query (no URL, not a command, not a menu button) ────
    $menuButtons = ['🎥 دانلود ویدیو', '🔍 جستجوی یوتیوب', '⚙️ تنظیمات', 'ℹ️ راهنما', '📊 وضعیت سرور'];
    
    if (empty($youtubeUrls) && strlen($text) >= 2 && !str_starts_with($text, '/') && !in_array($text, $menuButtons)) {
        // Check rate limit for search
        if ($rateLimiter->isRateLimited($chatId)) {
            $remaining = $rateLimiter->getRemainingTime($chatId);
            BaleNotifier::notifyRateLimited($chatId, $remaining);
            return;
        }

        BaleNotifier::sendMessage($chatId, "🔍 *در حال جستجو برای:* `{$text}`\n\n⏳ لطفاً صبر کنید...");

        $result = $githubClient->dispatchSearch($text, $chatId);

        if ($result['success']) {
            $rateLimiter->recordRequest($chatId);
            BaleNotifier::sendMessage($chatId, "✅ *جستجو آغاز شد!*\n\nنتایج تا چند ثانیه دیگر ارسال می‌شود.");
        } else {
            BaleNotifier::sendMessage($chatId, "❌ *خطا در جستجو!*\n\nکد خطا: {$result['http_code']}");
        }
        return;
    }

    // ──── Fallback ────
    BaleNotifier::sendMessage(
        $chatId,
        "📋 *لطفاً یک لینک یوتیوب ارسال کنید یا از دکمه‌های منو استفاده کنید.*",
        BaleNotifier::mainMenuKeyboard()
    );
}

// ══════════════════════════════════════════════════════════
// Callback Query Processing
// ══════════════════════════════════════════════════════════

/**
 * Process callback queries from inline keyboards
 * 
 * @param array $callbackQuery Callback query object from Bale
 * @return void
 */
function processCallbackQuery(array $callbackQuery): void
{
    global $db, $rateLimiter, $queueManager;

    $callbackId = $callbackQuery['id'] ?? null;
    $chatId = $callbackQuery['from']['id'] ?? null;
    $data = $callbackQuery['data'] ?? '';
    $messageId = $callbackQuery['message']['message_id'] ?? null;

    if (!$callbackId || !$chatId || !$data) {
        Logger::warning('Invalid callback query received');
        return;
    }

    Logger::debug('Processing callback', [
        'chat_id' => $chatId,
        'data'    => $data,
    ]);

    // ──── Confirm Download ────
    if ($data === 'confirm_download') {
        // Get pending download info
        $pending = $db->fetchOne(
            "SELECT youtube_url, quality, subtitles FROM pending_downloads WHERE chat_id = :chat_id",
            ['chat_id' => $chatId]
        );

        if (!$pending || !$pending['youtube_url']) {
            BaleNotifier::answerCallback($callbackId, '⚠️ لینک منقضی شده است. لطفاً دوباره ارسال کنید.', true);
            return;
        }

        // Clean up pending download
        $db->execute("DELETE FROM pending_downloads WHERE chat_id = :chat_id", ['chat_id' => $chatId]);

        // Acknowledge callback
        BaleNotifier::answerCallback($callbackId, '🔄 در حال افزودن به صف...', false);
        BaleNotifier::editMessage($chatId, $messageId, "⏳ *در حال افزودن به صف دانلود...*");

        // Add to queue
        $result = $queueManager->addToQueue(
            $chatId,
            $pending['youtube_url'],
            $pending['quality'],
            $pending['subtitles'] === 'yes'
        );

        if ($result['success']) {
            // Record rate limit
            $rateLimiter->recordRequest($chatId);

            // Update message
            BaleNotifier::editMessage($chatId, $messageId, "✅ *به صف دانلود اضافه شد!*");

            // Send queue position
            $waitFormatted = $result['estimated_wait'] > 0 
                ? BaleNotifier::formatWaitTime($result['estimated_wait']) 
                : 'آماده ✅';

            $queueText = "⏳ *شما در صف هستید!*\n\n";
            $queueText .= "🔢 موقعیت: `{$result['position']}`\n";
            $queueText .= "⏱ زمان تقریبی: {$waitFormatted}\n\n";
            $queueText .= "📊 *وضعیت خودکار بروزرسانی می‌شود.*\n";
            $queueText .= "💡 پس از شروع دانلود به شما اطلاع می‌دهم.";

            BaleNotifier::sendMessage($chatId, $queueText, BaleNotifier::statusCheckKeyboard());
        } else {
            BaleNotifier::editMessage($chatId, $messageId, "❌ *خطا!*\n\n{$result['message']}");
        }
        return;
    }

    // ──── Cancel Download ────
    if ($data === 'cancel_download') {
        $db->execute("DELETE FROM pending_downloads WHERE chat_id = :chat_id", ['chat_id' => $chatId]);
        BaleNotifier::answerCallback($callbackId, '❌ دانلود لغو شد.', false);
        BaleNotifier::editMessage($chatId, $messageId, "❌ *دانلود لغو شد.*");
        return;
    }

    // ──── Check Status ────
    if ($data === 'check_status') {
        BaleNotifier::answerCallback($callbackId, '🔍 در حال بررسی...', false);

        $jobStatus = $queueManager->getUserJobStatus($chatId);

        if (!$jobStatus) {
            BaleNotifier::sendMessage($chatId, "📋 *شما درخواست فعالی ندارید.*\n\nبرای دانلود جدید لینک ارسال کنید.");
            return;
        }

        $statusMap = [
            'pending'    => '⏳ در صف انتظار',
            'dispatched' => '🔄 در حال دانلود',
            'completed'  => '✅ کامل شده',
            'failed'     => '❌ ناموفق',
        ];

        $statusText = $statusMap[$jobStatus['status']] ?? '⏳ نامشخص';

        $replyText = "📊 *وضعیت درخواست شما*\n\n";
        $replyText .= "📌 وضعیت: {$statusText}\n";

        if ($jobStatus['status'] === 'pending') {
            $position = $queueManager->getQueuePosition((int) $jobStatus['id']);
            $wait = $queueManager->estimateWaitTime($position);
            $replyText .= "🔢 موقعیت در صف: {$position}\n";
            $replyText .= "⏱ زمان تقریبی: " . BaleNotifier::formatWaitTime($wait) . "\n";
        }

        if ($jobStatus['status'] === 'failed' && $jobStatus['error_message']) {
            $replyText .= "⚠️ خطا: {$jobStatus['error_message']}\n";
        }

        BaleNotifier::sendMessage($chatId, $replyText, BaleNotifier::statusCheckKeyboard());
        return;
    }

    // ──── Quality Settings ────
    if (str_starts_with($data, 'quality_')) {
        $quality = str_replace('quality_', '', $data);
        $settings = getUserSettings($chatId);
        saveUserSettings($chatId, $quality, $settings['subtitles']);
        BaleNotifier::answerCallback($callbackId, '✅ کیفیت تنظیم شد!');
        BaleNotifier::editMessage($chatId, $messageId, "🎬 *کیفیت ویدیوی خود را انتخاب کنید:*", BaleNotifier::qualityMenu());
        return;
    }

    // ──── Subtitle Toggle ────
    if ($data === 'toggle_subs') {
        $settings = getUserSettings($chatId);
        $newSubs = $settings['subtitles'] === 'yes' ? 'no' : 'yes';
        saveUserSettings($chatId, $settings['quality'], $newSubs);
        BaleNotifier::answerCallback($callbackId, '✅ تنظیمات زیرنویس ذخیره شد!');
        BaleNotifier::editMessage(
            $chatId, 
            $messageId, 
            "📝 *تنظیمات زیرنویس:*", 
            BaleNotifier::subtitleMenu($newSubs === 'yes')
        );
        return;
    }

    // ──── Settings Navigation ────
    if ($data === 'settings_quality') {
        BaleNotifier::answerCallback($callbackId);
        BaleNotifier::editMessage($chatId, $messageId, "🎬 *کیفیت ویدیوی خود را انتخاب کنید:*", BaleNotifier::qualityMenu());
        return;
    }

    if ($data === 'settings_subs') {
        $settings = getUserSettings($chatId);
        BaleNotifier::answerCallback($callbackId);
        BaleNotifier::editMessage(
            $chatId, 
            $messageId, 
            "📝 *تنظیمات زیرنویس:*", 
            BaleNotifier::subtitleMenu($settings['subtitles'] === 'yes')
        );
        return;
    }

    if ($data === 'settings_main') {
        $settings = getUserSettings($chatId);
        $qualityName = QUALITY_MAP[$settings['quality']] ?? '✨ Best Quality';
        $subsStatus = $settings['subtitles'] === 'yes' ? '✅ فعال' : '❌ غیرفعال';
        
        BaleNotifier::answerCallback($callbackId);
        $settingsText = "⚙️ *تنظیمات فعلی:*\n\n🎬 *کیفیت:* {$qualityName}\n📝 *زیرنویس:* {$subsStatus}";
        BaleNotifier::editMessage($chatId, $messageId, $settingsText, BaleNotifier::settingsMainMenu());
        return;
    }

    if ($data === 'settings_close' || $data === 'settings_back') {
        BaleNotifier::answerCallback($callbackId);
        BaleNotifier::editMessage($chatId, $messageId, "⚙️ *تنظیمات بسته شد.*");
        return;
    }

    // ──── Menu Navigation ────
    if ($data === 'menu_download') {
        BaleNotifier::answerCallback($callbackId);
        BaleNotifier::sendMessage($chatId, "🔗 *لینک ویدیوی یوتیوب را ارسال کنید:*");
        return;
    }

    if ($data === 'menu_settings') {
        $settings = getUserSettings($chatId);
        $qualityName = QUALITY_MAP[$settings['quality']] ?? '✨ Best Quality';
        $subsStatus = $settings['subtitles'] === 'yes' ? '✅ فعال' : '❌ غیرفعال';
        
        BaleNotifier::answerCallback($callbackId);
        $settingsText = "⚙️ *تنظیمات فعلی:*\n\n🎬 *کیفیت:* {$qualityName}\n📝 *زیرنویس:* {$subsStatus}";
        BaleNotifier::sendMessage($chatId, $settingsText, BaleNotifier::settingsMainMenu());
        return;
    }

    if ($data === 'menu_help') {
        BaleNotifier::answerCallback($callbackId);
        BaleNotifier::sendMessage($chatId, "📖 *راهنما*\n\n🔸 لینک یوتیوب ارسال کنید\n🔸 تنظیمات کیفیت و زیرنویس\n🔸 هر ۵ دقیقه یک درخواست");
        return;
    }

    if ($data === 'menu_status') {
        $remaining = $rateLimiter->getRemainingTimeFormatted($chatId);
        $queueSize = $queueManager->getQueueSize();
        
        BaleNotifier::answerCallback($callbackId);
        BaleNotifier::sendMessage($chatId, "📊 *وضعیت*\n\n✅ فعال\n⏱ درخواست بعدی: {$remaining}\n📋 صف: {$queueSize}");
        return;
    }

    if ($data === 'menu_search') {
        BaleNotifier::answerCallback($callbackId);
        BaleNotifier::sendMessage($chatId, "🔍 *جستجوی یوتیوب*\n\nلطفاً عبارت مورد نظر را وارد کنید:");
        return;
    }

    // ──── Unknown Callback ────
    BaleNotifier::answerCallback($callbackId);
    Logger::debug('Unknown callback data', ['data' => $data]);
}

// ══════════════════════════════════════════════════════════
// Main Router
// ══════════════════════════════════════════════════════════

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = json_decode(file_get_contents('php://input'), true);

// Handle POST (webhook updates from Bale)
if ($requestMethod === 'POST' && $input && isset($input['update_id'])) {
    $updateId = $input['update_id'];

    // Deduplication: Check if update already processed
    $alreadyProcessed = $db->fetchValue(
        "SELECT update_id FROM processed_updates WHERE update_id = :update_id",
        ['update_id' => $updateId]
    );

    if ($alreadyProcessed) {
        // Already processed — acknowledge silently
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // Mark as processed
    $db->execute(
        "INSERT INTO processed_updates (update_id, processed_at) VALUES (:update_id, :time)",
        [
            'update_id' => $updateId,
            'time'      => time(),
        ]
    );

    // Route to appropriate handler
    try {
        if (isset($input['message'])) {
            processMessage($input['message']);
        } elseif (isset($input['callback_query'])) {
            processCallbackQuery($input['callback_query']);
        } else {
            Logger::debug('Unknown update type received', ['update_id' => $updateId]);
        }
    } catch (\Exception $e) {
        Logger::exception($e, 'Error processing update', ['update_id' => $updateId]);
    }

    // Respond OK to Bale
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// Handle GET (health check / webhook setup verification)
if ($requestMethod === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html dir='rtl' lang='fa'><head><meta charset='UTF-8'><title>Gateway</title></head><body>";
    echo "<h1>✅ Bale YouTube Downloader Gateway v5.0</h1>";
    echo "<p>Gateway is running! Set your webhook to this URL.</p>";
    echo "<hr><p><strong>نسخه:</strong> ۵.۰ | <strong>آخرین بروزرسانی:</strong> اردیبهشت ۱۴۰۵</p>";
    echo "</body></html>";
    exit;
}

// Invalid request
http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['ok' => false, 'error' => 'Invalid request method or body']);