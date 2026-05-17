<?php
/**
 * ============================================================
 * gateway.php — Bale YouTube Downloader Gateway (v5.0.3)
 * ============================================================
 * 
 * 🏗️ Architecture Layer: 3 - Entry Point (Webhook Handler)
 * 📦 Part of: Khashayar YouTube Downloader - Queue System
 * 🔗 Dependency: QueueManager (File 7) + all underlying layers
 * 
 * 🔧 PATCHED v5.0.3: 
 *   • Added "dl_" callback handler for search result downloads
 *   • Added search rate limiting (60 seconds between searches)
 *   • All chatId params accept string|int for Bale compatibility
 * 
 * @package     https://github.com/khashayardev/Bale-YouTube-Downloader
 * @version     5.0.3
 * @author      Khashayar
 * @license     MIT
 * @since       2026-05-16
 */

declare(strict_types=1);

// ══════════════════════════════════════════════════════════
// CRITICAL: Environment Variables — قبل از هر require ای
// ══════════════════════════════════════════════════════════

putenv('BALE_BOT_TOKEN=YOUR-BALE-BOT-TOKE-HERE');
putenv('GH_PAT=YOU-GITHUB-TOKEN-HERE');
putenv('GITHUB_OWNER=GITHUB-USERNEAME-HERE');
putenv('GITHUB_REPO=Bale-YouTube-Downloader');
putenv('CHANNEL_ID=BALE-CHANNEL-ID-HERE');

// ══════════════════════════════════════════════════════════
// Error Handling — برای debugging
// ══════════════════════════════════════════════════════════

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
ini_set('error_log', $logDir . '/php_errors.log');

// ══════════════════════════════════════════════════════════
// Bootstrap: Load all dependencies
// ══════════════════════════════════════════════════════════

define('APP_RUNNING', true);
define('SKIP_CONFIG_VALIDATION', true);

require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Logger.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/RateLimiter.php';
require_once __DIR__ . '/lib/BaleNotifier.php';
require_once __DIR__ . '/lib/GitHubClient.php';
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
    error_log('[Gateway] Init error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Internal server error']);
    exit;
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
 * @param string|int $chatId User's chat ID
 * @return array{quality: string, subtitles: string}
 */
function getUserSettings(string|int $chatId): array
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
 * @param string|int $chatId User's chat ID
 * @param string $quality Quality setting
 * @param string $subtitles Subtitle setting ('yes' or 'no')
 * @return void
 */
function saveUserSettings(string|int $chatId, string $quality, string $subtitles): void
{
    global $db;
    $db->execute(
        "INSERT INTO user_settings (chat_id, quality, subtitles, updated_at) 
         VALUES (:chat_id, :quality, :subs, :time)
         ON CONFLICT(chat_id) DO UPDATE SET 
             quality = :quality2, subtitles = :subs2, updated_at = :time2",
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
// Search Rate Limiting (60 seconds between searches)
// ══════════════════════════════════════════════════════════

/**
 * Check if user is rate limited for search
 * Separate from download rate limiting
 * 
 * @param string|int $chatId User's chat ID
 * @return bool True if rate limited
 */
function isSearchRateLimited(string|int $chatId): bool
{
    global $db;
    
    $db->execute(
        "CREATE TABLE IF NOT EXISTS search_rate_limits (
            chat_id TEXT PRIMARY KEY, 
            last_search_time INTEGER
        )"
    );
    
    $lastSearch = $db->fetchValue(
        "SELECT last_search_time FROM search_rate_limits WHERE chat_id = :chat_id",
        ['chat_id' => $chatId]
    );
    
    if ($lastSearch === null) {
        return false;
    }
    
    $elapsed = time() - (int) $lastSearch;
    $searchCooldown = 60; // ۶۰ ثانیه بین هر جستجو
    
    return $elapsed < $searchCooldown;
}

/**
 * Get remaining search cooldown in seconds
 * 
 * @param string|int $chatId User's chat ID
 * @return int Seconds remaining
 */
function getSearchRemainingTime(string|int $chatId): int
{
    global $db;
    
    $lastSearch = $db->fetchValue(
        "SELECT last_search_time FROM search_rate_limits WHERE chat_id = :chat_id",
        ['chat_id' => $chatId]
    );
    
    if ($lastSearch === null) {
        return 0;
    }
    
    $elapsed = time() - (int) $lastSearch;
    $remaining = 60 - $elapsed;
    
    return max(0, $remaining);
}

/**
 * Record a search request for rate limiting
 * 
 * @param string|int $chatId User's chat ID
 * @return void
 */
function recordSearchRequest(string|int $chatId): void
{
    global $db;
    
    $db->execute(
        "INSERT INTO search_rate_limits (chat_id, last_search_time) 
         VALUES (:chat_id, :time)
         ON CONFLICT(chat_id) DO UPDATE SET last_search_time = :time2",
        [
            'chat_id' => $chatId,
            'time'    => time(),
            'time2'   => time(),
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
    global $db, $rateLimiter, $queueManager, $githubClient;

    $chatId = $message['chat']['id'] ?? null;
    $text = $message['text'] ?? '';

    if (!$chatId) {
        Logger::warning('Message without chat ID received');
        return;
    }

    // Ignore messages from channels (prevent infinite loop)
    if (isset($message['sender_chat'])) {
        Logger::debug('Ignoring channel message', ['chat_id' => $chatId]);
        return;
    }

    // Handle forwarded message from channel (channel registration)
    if (isset($message['forward_from_chat']) && $message['forward_from_chat']['type'] === 'channel') {
        $forwardChat = $message['forward_from_chat'];
        $channelId = $forwardChat['id'];
        $channelUsername = $forwardChat['username'] ?? '';

        // Save user's channel
        $db->execute(
            "INSERT INTO user_channels (chat_id, channel_id, channel_username, verified_at, is_active) 
             VALUES (:chat_id, :channel_id, :username, :time, 1)
             ON CONFLICT(chat_id) DO UPDATE SET 
                 channel_id = :channel_id2,
                 channel_username = :username2,
                 verified_at = :time2,
                 is_active = 1",
            [
                'chat_id'      => $chatId,
                'channel_id'   => (string) $channelId,
                'channel_id2'  => (string) $channelId,
                'username'     => $channelUsername,
                'username2'    => $channelUsername,
                'time'         => time(),
                'time2'        => time(),
            ]
        );

        // Check if bot is admin
        $botId = explode(':', BALE_BOT_TOKEN)[0];
        $isAdmin = BaleNotifier::isBotAdmin($channelId, $botId);

        if ($isAdmin) {
            BaleNotifier::sendMessage(
                $chatId,
                "✅ *کانال شما با موفقیت ثبت شد!*\n\n📢 کانال: @{$channelUsername}\n🛡️ ربات ادمین کانال شماست.\n\n🎥 حالا می‌توانید دانلود کنید.",
                BaleNotifier::mainMenuKeyboard()
            );
        } else {
            BaleNotifier::sendMessage(
                $chatId,
                "⚠️ *کانال شناسایی شد اما ربات ادمین نیست!*\n\n📢 کانال: @{$channelUsername}\n\nلطفاً ربات را *ادمین* کانال کنید (دسترسی ارسال پیام).\nسپس دکمه بررسی وضعیت را بزنید.",
                ['inline_keyboard' => [[['text' => '🔄 بررسی ادمین بودن', 'callback_data' => 'check_admin']]]]
            );
        }
        return;
    }

    Logger::debug('Processing message', [
        'chat_id' => $chatId,
        'text'    => substr($text, 0, 100),
    ]);

    // ──── /start Command ────
    if (str_starts_with($text, '/start')) {
        // Check forced join
        $membership = BaleNotifier::getChatMember(FORCE_JOIN_CHANNEL_ID, $chatId);
        
        if (!$membership['is_member']) {
            $joinText = "📢 *برای استفاده از ربات، ابتدا باید عضو کانال شوید!*\n\n";
            $joinText .= "🔸 روی دکمه زیر کلیک کنید و عضو کانال شوید.\n";
            $joinText .= "🔸 سپس دکمه *بررسی عضویت* را بزنید.";
            BaleNotifier::sendMessage($chatId, $joinText, BaleNotifier::forceJoinKeyboard());
            return;
        }

        // Check if user has registered archive channel
        $userChannel = $db->fetchOne(
            "SELECT channel_id FROM user_channels WHERE chat_id = :chat_id AND is_active = 1",
            ['chat_id' => $chatId]
        );

        if (!$userChannel) {
            $noChannelText = "📢 *برای ادامه، باید کانال آرشیو خود را معرفی کنید!*\n\n";
            $noChannelText .= "🔸 یک کانال در بله بسازید.\n";
            $noChannelText .= "🔸 ربات را *ادمین* کانال کنید.\n";
            $noChannelText .= "🔸 یک پیام از کانال برای ربات *Forward* کنید.\n\n";
            $noChannelText .= "📌 فایل‌های دانلودی در کانال شما آرشیو می‌شوند.";
            BaleNotifier::sendMessage($chatId, $noChannelText);
            return;
        }


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
        $helpText .= "🔸 *محدودیت:* هر ۵ دقیقه یک دانلود، هر ۶۰ ثانیه یک جستجو\n";
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
        $searchRemaining = getSearchRemainingTime($chatId);

        $statusText = "📊 *وضعیت سرور*\n\n";
        $statusText .= "✅ *سرویس:* فعال\n";
        $statusText .= "⏱ *درخواست دانلود بعدی:* {$remaining}\n";
        $statusText .= "🔍 *جستجوی بعدی:* " . ($searchRemaining > 0 ? "{$searchRemaining} ثانیه دیگر" : "آماده ✅") . "\n";
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
        case '🔄 بروزرسانی کانال':
            BaleNotifier::sendMessage($chatId, "📢 لطفاً یک پیام از کانال جدید خود را *Forward* کنید.");
            return;
            
        case '🎥 دانلود ویدیو':
            BaleNotifier::sendMessage(
                $chatId,
                "🔗 *لینک ویدیوی یوتیوب را ارسال کنید:*\n\n_مثال: https://youtu.be/abc123def45_"
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
            $helpText .= "🔸 هر ۵ دقیقه یک دانلود، هر ۶۰ ثانیه یک جستجو\n";
            $helpText .= "🔸 در زمان شلوغی، درخواست در صف قرار می‌گیرد";
            BaleNotifier::sendMessage($chatId, $helpText);
            return;

        case '📊 وضعیت سرور':
            $remaining = $rateLimiter->getRemainingTimeFormatted($chatId);
            $queueSize = $queueManager->getQueueSize();
            $searchRemaining = getSearchRemainingTime($chatId);

            $statusText = "📊 *وضعیت سرور*\n\n";
            $statusText .= "✅ سرویس: فعال\n";
            $statusText .= "⏱ دانلود بعدی: {$remaining}\n";
            $statusText .= "🔍 جستجوی بعدی: " . ($searchRemaining > 0 ? "{$searchRemaining} ثانیه" : "آماده ✅") . "\n";
            $statusText .= "📋 کارهای در صف: {$queueSize}";
            BaleNotifier::sendMessage($chatId, $statusText);
            return;
    }

    // ──── YouTube URL Detection ────
    $youtubeUrls = extractYoutubeUrls($text);

    if (!empty($youtubeUrls)) {
        // Check if user has registered a channel
        $userChannel = $db->fetchOne(
            "SELECT channel_id FROM user_channels WHERE chat_id = :chat_id AND is_active = 1",
            ['chat_id' => $chatId]
        );

        if (!$userChannel) {
            $noChannelText = "📢 *شما هنوز کانال آرشیو خود را معرفی نکرده‌اید!*\n\n";
            $noChannelText .= "🔸 یک کانال در بله بسازید.\n";
            $noChannelText .= "🔸 ربات را *ادمین* کانال کنید.\n";
            $noChannelText .= "🔸 یک پیام از کانال برای ربات *Forward* کنید.\n\n";
            $noChannelText .= "📌 فایل‌های دانلودی در کانال شما آرشیو می‌شوند.";
            BaleNotifier::sendMessage($chatId, $noChannelText);
            return;
        }

        // Check download rate limit
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
            "CREATE TABLE IF NOT EXISTS pending_downloads (
                chat_id TEXT PRIMARY KEY, 
                youtube_url TEXT, 
                quality TEXT, 
                subtitles TEXT, 
                created_at INTEGER
            )"
        );
        
        $db->execute(
            "INSERT OR REPLACE INTO pending_downloads 
             (chat_id, youtube_url, quality, subtitles, created_at) 
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

    // ──── Search Query ────
    $menuButtons = [
        '🎥 دانلود ویدیو', '🔍 جستجوی یوتیوب', '⚙️ تنظیمات', 
        'ℹ️ راهنما', '📊 وضعیت سرور'
    ];
    
    if (empty($youtubeUrls) && 
        strlen($text) >= 2 && 
        !str_starts_with($text, '/') && 
        !in_array($text, $menuButtons)) {
        
        // Check search rate limit (۶۰ ثانیه)
        if (isSearchRateLimited($chatId)) {
            $remaining = getSearchRemainingTime($chatId);
            BaleNotifier::sendMessage(
                $chatId,
                "⏳ *لطفاً کمی صبر کنید!*\n\n🔍 جستجوی بعدی: {$remaining} ثانیه دیگر\n\n💡 برای دانلود مستقیم، لینک یوتیوب را ارسال کنید."
            );
            return;
        }

        BaleNotifier::sendMessage(
            $chatId, 
            "🔍 *در حال جستجو برای:* `{$text}`\n\n⏳ لطفاً صبر کنید..."
        );

        $result = $githubClient->dispatchSearch($text, $chatId);

        if ($result['success']) {
            // Record search rate limit
            recordSearchRequest($chatId);
            
            BaleNotifier::sendMessage(
                $chatId, 
                "✅ *جستجو آغاز شد!*\n\nنتایج تا چند ثانیه دیگر ارسال می‌شود."
            );
        } else {
            BaleNotifier::sendMessage(
                $chatId, 
                "❌ *خطا در جستجو!*\n\nکد خطا: {$result['http_code']}"
            );
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
    global $db, $rateLimiter, $queueManager, $githubClient;

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
        $pending = $db->fetchOne(
            "SELECT youtube_url, quality, subtitles 
             FROM pending_downloads 
             WHERE chat_id = :chat_id",
            ['chat_id' => $chatId]
        );

        if (!$pending || !$pending['youtube_url']) {
            BaleNotifier::answerCallback(
                $callbackId, 
                '⚠️ لینک منقضی شده است. لطفاً دوباره ارسال کنید.', 
                true
            );
            return;
        }

        $db->execute(
            "DELETE FROM pending_downloads WHERE chat_id = :chat_id",
            ['chat_id' => $chatId]
        );

        BaleNotifier::answerCallback($callbackId, '🔄 در حال افزودن به صف...', false);
        BaleNotifier::editMessage(
            $chatId, 
            $messageId, 
            "⏳ *در حال افزودن به صف دانلود...*"
        );
        
        // حذف job های قبلی این کاربر که مونده بودن
        $db->execute(
            "UPDATE pending_queue SET status = 'failed', error_message = 'User retried' WHERE chat_id = :chat_id AND status IN ('dispatched', 'pending')",
            ['chat_id' => $chatId]
        );

        $result = $queueManager->addToQueue(
            $chatId,
            $pending['youtube_url'],
            $pending['quality'],
            $pending['subtitles'] === 'yes'
        );

        if ($result['success']) {
            $rateLimiter->recordRequest($chatId);
            BaleNotifier::editMessage(
                $chatId, 
                $messageId, 
                "✅ *به صف دانلود اضافه شد!*"
            );
            BaleNotifier::sendMessage(
                $chatId, 
                $result['message'], 
                BaleNotifier::statusCheckKeyboard()
            );
        } else {
            BaleNotifier::editMessage(
                $chatId, 
                $messageId, 
                "❌ *خطا!*\n\n{$result['message']}"
            );
        }
        return;
    }

    // ──── Cancel Download ────
    if ($data === 'cancel_download') {
        $db->execute(
            "DELETE FROM pending_downloads WHERE chat_id = :chat_id",
            ['chat_id' => $chatId]
        );
        BaleNotifier::answerCallback($callbackId, '❌ دانلود لغو شد.', false);
        BaleNotifier::editMessage($chatId, $messageId, "❌ *دانلود لغو شد.*");
        return;
    }

    // ──── Check Status (with Smart Queue Trigger) ────
    if ($data === 'check_status') {
        BaleNotifier::answerCallback($callbackId, '🔍 در حال بررسی...', false);

        $jobStatus = $queueManager->getUserJobStatus($chatId);

        if (!$jobStatus) {
            BaleNotifier::sendMessage(
                $chatId, 
                "📋 *شما درخواست فعالی ندارید.*\n\nبرای دانلود جدید لینک ارسال کنید."
            );
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

        // ──── Smart Queue Trigger ────
        $triggerCooldown = 120; // ۱۲۰ ثانیه بین هر trigger
        $triggerFile = __DIR__ . '/data/last_trigger.txt';
        $lastTrigger = (int) (@file_get_contents($triggerFile) ?: 0);
        $elapsed = time() - $lastTrigger;
        $queueSize = $queueManager->getQueueSize();

        if ($queueSize > 0 && $elapsed >= $triggerCooldown) {
            @file_put_contents($triggerFile, (string) time());
            require __DIR__ . '/queue_processor.php';
            $replyText .= "\n🔄 *پردازش صف آغاز شد.*";
        } elseif ($queueSize > 0) {
            $remaining = $triggerCooldown - $elapsed;
            $replyText .= "\n⏳ *بررسی مجدد صف دانلود:* {$remaining} ثانیه دیگر.";
        } else {
            $replyText .= "\n📋 *صف دانلود خالی است.*";
        }

        // پیام فعلی رو ویرایش کن (به جای ارسال پیام جدید)
        BaleNotifier::editMessage($chatId, $messageId, $replyText, BaleNotifier::statusCheckKeyboard());
        
        // نمایش اطلاعات به صورت popup
        $popupText = "📌 {$statusText}";
        if ($jobStatus['status'] === 'pending') {
            $popupText .= "\n🔢 صف: {$position}\n⏱ " . BaleNotifier::formatWaitTime($wait);
        }
        BaleNotifier::answerCallback($callbackId, $popupText, true);
        return;
    }

    // ──── Quality Settings ────
    if (str_starts_with($data, 'quality_')) {
        $quality = str_replace('quality_', '', $data);
        $settings = getUserSettings($chatId);
        saveUserSettings($chatId, $quality, $settings['subtitles']);
        BaleNotifier::answerCallback($callbackId, '✅ کیفیت تنظیم شد!');
        BaleNotifier::editMessage(
            $chatId, 
            $messageId, 
            "🎬 *کیفیت ویدیوی خود را انتخاب کنید:*", 
            BaleNotifier::qualityMenu()
        );
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
        BaleNotifier::editMessage(
            $chatId,
            $messageId,
            "🎬 *کیفیت ویدیوی خود را انتخاب کنید:*",
            BaleNotifier::qualityMenu()
        );
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
        $settingsText = "⚙️ *تنظیمات فعلی:*\n\n";
        $settingsText .= "🎬 *کیفیت:* {$qualityName}\n";
        $settingsText .= "📝 *زیرنویس:* {$subsStatus}";
        BaleNotifier::editMessage(
            $chatId,
            $messageId,
            $settingsText,
            BaleNotifier::settingsMainMenu()
        );
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
        BaleNotifier::sendMessage(
            $chatId,
            "🔗 *لینک ویدیوی یوتیوب را ارسال کنید:*"
        );
        return;
    }

    if ($data === 'menu_settings') {
        $settings = getUserSettings($chatId);
        $qualityName = QUALITY_MAP[$settings['quality']] ?? '✨ Best Quality';
        $subsStatus = $settings['subtitles'] === 'yes' ? '✅ فعال' : '❌ غیرفعال';

        BaleNotifier::answerCallback($callbackId);
        $settingsText = "⚙️ *تنظیمات فعلی:*\n\n";
        $settingsText .= "🎬 *کیفیت:* {$qualityName}\n";
        $settingsText .= "📝 *زیرنویس:* {$subsStatus}";
        BaleNotifier::sendMessage(
            $chatId,
            $settingsText,
            BaleNotifier::settingsMainMenu()
        );
        return;
    }

    if ($data === 'menu_help') {
        BaleNotifier::answerCallback($callbackId);
        BaleNotifier::sendMessage(
            $chatId,
            "📖 *راهنما*\n\n🔸 لینک یوتیوب ارسال کنید\n🔸 تنظیمات کیفیت و زیرنویس\n🔸 هر ۵ دقیقه یک دانلود\n🔸 هر ۶۰ ثانیه یک جستجو"
        );
        return;
    }

    if ($data === 'menu_status') {
        $remaining = $rateLimiter->getRemainingTimeFormatted($chatId);
        $queueSize = $queueManager->getQueueSize();
        $searchRemaining = getSearchRemainingTime($chatId);

        BaleNotifier::answerCallback($callbackId);
        BaleNotifier::sendMessage(
            $chatId,
            "📊 *وضعیت*\n\n✅ فعال\n⏱ دانلود بعدی: {$remaining}\n🔍 جستجوی بعدی: " . ($searchRemaining > 0 ? "{$searchRemaining} ثانیه" : "آماده ✅") . "\n📋 صف: {$queueSize}"
        );
        return;
    }

    if ($data === 'menu_search') {
        BaleNotifier::answerCallback($callbackId);
        BaleNotifier::sendMessage(
            $chatId,
            "🔍 *جستجوی یوتیوب*\n\nلطفاً عبارت مورد نظر را وارد کنید:"
        );
        return;
    }

    // ──── Download from Search Results (dl_VIDEO_ID) ────
    if (str_starts_with($data, 'dl_')) {
        $videoId = str_replace('dl_', '', $data);
        $youtubeUrl = "https://www.youtube.com/watch?v={$videoId}";

        BaleNotifier::answerCallback($callbackId, '🔄 در حال بررسی...', false);

        // Check download rate limit
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

        // Add to queue directly (skip confirmation for search results)
        $result = $queueManager->addToQueue(
            $chatId,
            $youtubeUrl,
            $settings['quality'],
            $settings['subtitles'] === 'yes'
        );

        if ($result['success']) {
            $rateLimiter->recordRequest($chatId);
            BaleNotifier::editMessage(
                $chatId,
                $messageId,
                "✅ *به صف دانلود اضافه شد!*\n\n🎬 کیفیت: " . (QUALITY_MAP[$settings['quality']] ?? 'Best')
            );
            BaleNotifier::sendMessage(
                $chatId,
                $result['message'],
                BaleNotifier::statusCheckKeyboard()
            );
        } else {
            BaleNotifier::editMessage(
                $chatId,
                $messageId,
                "❌ *خطا!*\n\n{$result['message']}"
            );
        }
        return;
    }

    // ──── Check Join (Force Join Verification) ────
    if ($data === 'check_join') {
        $membership = BaleNotifier::getChatMember(FORCE_JOIN_CHANNEL_ID, $chatId);
        
        if ($membership['is_member']) {
            BaleNotifier::answerCallback($callbackId, '✅ عضویت شما تأیید شد!', false);
            BaleNotifier::editMessage($chatId, $messageId, "✅ *عضویت شما تأیید شد!*\n\nحالا می‌توانید از ربات استفاده کنید.");
            
            // Check if user has registered archive channel
            $userChannel = $db->fetchOne(
                "SELECT channel_id FROM user_channels WHERE chat_id = :chat_id AND is_active = 1",
                ['chat_id' => $chatId]
            );

            if (!$userChannel) {
                $noChannelText = "📢 *برای ادامه، باید کانال آرشیو خود را معرفی کنید!*\n\n";
                $noChannelText .= "🔸 یک کانال در بله بسازید.\n";
                $noChannelText .= "🔸 ربات را *ادمین* کانال کنید.\n";
                $noChannelText .= "🔸 یک پیام از کانال برای ربات *Forward* کنید.\n\n";
                $noChannelText .= "📌 فایل‌های دانلودی در کانال شما آرشیو می‌شوند.";
                BaleNotifier::sendMessage($chatId, $noChannelText);
                return;
            }

            $welcomeText = "🎬 *سلام! به ربات دانلودر یوتیوب خوش آمدید!*\n\n";
            $welcomeText .= "👇 یکی از گزینه‌های زیر را انتخاب کنید:";
            BaleNotifier::sendMessage($chatId, $welcomeText, BaleNotifier::startMenu());
        } else {
            BaleNotifier::answerCallback($callbackId, '❌ هنوز عضو نشده‌اید!', true);
        }
        return;
    }

    // ──── Verify Channel (from forwarded message) ────
    if ($data === 'verify_channel') {
        // This is handled in processMessage when user forwards from channel
        BaleNotifier::answerCallback($callbackId, 'ℹ️ لطفاً یک پیام از کانال خود Forward کنید.', false);
        return;
    }

    // ──── Update Channel (re-forward from new channel) ────
    if ($data === 'update_channel') {
        BaleNotifier::answerCallback($callbackId, '📢 لطفاً یک پیام از کانال جدید Forward کنید.', false);
        BaleNotifier::editMessage($chatId, $messageId, "📢 *بروزرسانی کانال آرشیو*\n\nلطفاً یک پیام از کانال جدید خود را *Forward* کنید.\n\nبا این کار، کانال آرشیو شما بروزرسانی می‌شود.");
        return;
    }


    // ──── Check Admin (verify bot is admin in user's channel) ────
    if ($data === 'check_admin') {
        $userChannel = $db->fetchOne(
            "SELECT channel_id FROM user_channels WHERE chat_id = :chat_id AND is_active = 1",
            ['chat_id' => $chatId]
        );

        if (!$userChannel) {
            BaleNotifier::answerCallback($callbackId, '⚠️ ابتدا کانال خود را معرفی کنید.', true);
            return;
        }

        $botId = explode(':', BALE_BOT_TOKEN)[0];
        $isAdmin = BaleNotifier::isBotAdmin($userChannel['channel_id'], $botId);

        if ($isAdmin) {
            BaleNotifier::answerCallback($callbackId, '✅ ربات ادمین کانال شماست!', false);
            BaleNotifier::editMessage($chatId, $messageId, "✅ *ربات ادمین کانال شماست!*\n\nحالا می‌توانید دانلود کنید.");
        } else {
            BaleNotifier::answerCallback($callbackId, '❌ ربات هنوز ادمین نیست!', true);
            BaleNotifier::editMessage($chatId, $messageId, "❌ *ربات ادمین کانال شما نیست!*\n\nلطفاً ربات را ادمین کانال کنید.");
        }
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
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // Mark as processed
    $db->execute(
        "INSERT INTO processed_updates (update_id, processed_at) 
         VALUES (:update_id, :time)",
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
            Logger::debug('Unknown update type received', [
                'update_id' => $updateId,
            ]);
        }
    } catch (\Exception $e) {
        Logger::exception($e, 'Error processing update', [
            'update_id' => $updateId,
        ]);
        error_log('[Gateway] Update error: ' . $e->getMessage());
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
    echo "<!DOCTYPE html>";
    echo "<html dir='rtl' lang='fa'><head><meta charset='UTF-8'><title>Gateway</title></head><body>";
    echo "<h1>✅ Bale YouTube Downloader Gateway v5.0</h1>";
    echo "<p>Gateway is running! Set your webhook to this URL.</p>";
    echo "<hr>";
    echo "<p><strong>نسخه:</strong> ۵.۰ | <strong>آخرین بروزرسانی:</strong> اردیبهشت ۱۴۰۵</p>";
    echo "</body></html>";
    exit;
}

// Invalid request
http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['ok' => false, 'error' => 'Invalid request method or body']);
