<?php
/**
 * ============================================================
 * gateway.php — Bale YouTube Downloader Gateway (v6.0.0)
 * ============================================================
 * 
 * 🏗️ Architecture Layer: 3 - Entry Point (Webhook Handler)
 * 📦 Part of: Khashayar YouTube Downloader - Queue System
 * 🔗 Dependency: QueueManager (File 7) + all underlying layers
 * 
 * 🔧 REWRITTEN v6.0.0:
 *   • Complete state-machine for search/download/share
 *   • All keyboards are inline — no ReplyKeyboardMarkup
 *   • editMessage instead of sendMessage to reduce clutter
 *   • Back button on every submenu
 *   • share target check BEFORE search — no interference
 *   • Fixed pagination callback_data parsing
 *   • Search only when explicitly requested (menu button)
 * 
 * @package     KhashayarDownloader
 * @version     6.0.0
 * @author      Khashayar
 * @license     MIT
 * @since       2026-05-19
 */

declare(strict_types=1);

// ══════════════════════════════════════════════════════════
// CRITICAL: Environment Variables
// ══════════════════════════════════════════════════════════

putenv('BALE_BOT_TOKEN=' . (getenv('BALE_BOT_TOKEN') ?: 'YOUR_BALE_BOT_TOKEN_HERE'));
putenv('GH_PAT='         . (getenv('GH_PAT')         ?: 'YOUR_GITHUB_PAT_HERE'));
putenv('GITHUB_OWNER='   . (getenv('GITHUB_OWNER')   ?: 'YOUR_GITHUB_USERNAME'));
putenv('GITHUB_REPO='    . (getenv('GITHUB_REPO')    ?: 'Bale-YouTube-Downloader'));
putenv('CHANNEL_ID='     . (getenv('CHANNEL_ID')     ?: ''));

// ══════════════════════════════════════════════════════════
// Error Handling
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
// Bootstrap
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
    Logger::info('Gateway v6.0.0 initialized');
} catch (\Exception $e) {
    error_log('[Gateway] Init error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Internal server error']);
    exit;
}

// ══════════════════════════════════════════════════════════
// State Management Tables
// ══════════════════════════════════════════════════════════

$db->execute("CREATE TABLE IF NOT EXISTS user_state (
    chat_id TEXT PRIMARY KEY,
    state TEXT NOT NULL DEFAULT 'idle',
    data TEXT,
    created_at INTEGER,
    updated_at INTEGER
)");

$db->execute("CREATE TABLE IF NOT EXISTS pending_shares (
    chat_id TEXT PRIMARY KEY,
    youtube_url TEXT,
    video_id TEXT,
    created_at INTEGER
)");

$db->execute("CREATE TABLE IF NOT EXISTS pending_downloads (
    chat_id TEXT PRIMARY KEY,
    youtube_url TEXT,
    quality TEXT,
    subtitles TEXT,
    created_at INTEGER
)");

// ══════════════════════════════════════════════════════════
// YouTube URL Extraction
// ══════════════════════════════════════════════════════════

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
// User Settings
// ══════════════════════════════════════════════════════════

function getUserSettings(string|int $chatId): array
{
    global $db;
    $row = $db->fetchOne(
        "SELECT quality, subtitles FROM user_settings WHERE chat_id = :chat_id",
        ['chat_id' => $chatId]
    );
    return $row ?: ['quality' => 'best', 'subtitles' => 'no'];
}

function saveUserSettings(string|int $chatId, string $quality, string $subtitles): void
{
    global $db;
    $db->execute(
        "INSERT INTO user_settings (chat_id, quality, subtitles, updated_at)
         VALUES (:chat_id, :quality, :subs, :time)
         ON CONFLICT(chat_id) DO UPDATE SET
             quality = :quality2, subtitles = :subs2, updated_at = :time2",
        [
            'chat_id'   => $chatId, 'quality' => $quality, 'quality2' => $quality,
            'subs'      => $subtitles, 'subs2' => $subtitles,
            'time'      => time(), 'time2' => time(),
        ]
    );
}

// ══════════════════════════════════════════════════════════
// State Management Helpers
// ══════════════════════════════════════════════════════════

function setUserState(string|int $chatId, string $state, ?string $data = null): void
{
    global $db;
    $db->execute(
        "INSERT INTO user_state (chat_id, state, data, created_at, updated_at)
         VALUES (:chat_id, :state, :data, :time, :time2)
         ON CONFLICT(chat_id) DO UPDATE SET state = :state2, data = :data2, updated_at = :time3",
        [
            'chat_id' => $chatId, 'state' => $state, 'state2' => $state,
            'data' => $data, 'data2' => $data,
            'time' => time(), 'time2' => time(), 'time3' => time(),
        ]
    );
}

function getUserState(string|int $chatId): ?array
{
    global $db;
    return $db->fetchOne(
        "SELECT state, data FROM user_state WHERE chat_id = :chat_id",
        ['chat_id' => $chatId]
    );
}

function clearUserState(string|int $chatId): void
{
    global $db;
    $db->execute("DELETE FROM user_state WHERE chat_id = :chat_id", ['chat_id' => $chatId]);
}

// ══════════════════════════════════════════════════════════
// Search Rate Limiting
// ══════════════════════════════════════════════════════════

function isSearchRateLimited(string|int $chatId): bool
{
    global $db;
    $db->execute("CREATE TABLE IF NOT EXISTS search_rate_limits (chat_id TEXT PRIMARY KEY, last_search_time INTEGER)");
    $lastSearch = $db->fetchValue("SELECT last_search_time FROM search_rate_limits WHERE chat_id = :chat_id", ['chat_id' => $chatId]);
    if ($lastSearch === null) return false;
    $elapsed = time() - (int) $lastSearch;
    return $elapsed < 60;
}

function getSearchRemainingTime(string|int $chatId): int
{
    global $db;
    $lastSearch = $db->fetchValue("SELECT last_search_time FROM search_rate_limits WHERE chat_id = :chat_id", ['chat_id' => $chatId]);
    if ($lastSearch === null) return 0;
    return max(0, 60 - (time() - (int) $lastSearch));
}

function recordSearchRequest(string|int $chatId): void
{
    global $db;
    $db->execute(
        "INSERT INTO search_rate_limits (chat_id, last_search_time) VALUES (:chat_id, :time)
         ON CONFLICT(chat_id) DO UPDATE SET last_search_time = :time2",
        ['chat_id' => $chatId, 'time' => time(), 'time2' => time()]
    );
}

// ══════════════════════════════════════════════════════════
// Message Processing
// ══════════════════════════════════════════════════════════

function processMessage(array $message): void
{
    global $db, $rateLimiter, $queueManager, $githubClient;

    $chatId = $message['chat']['id'] ?? null;
    $text = $message['text'] ?? '';

    if (!$chatId) {
        Logger::warning('Message without chat ID received');
        return;
    }

    if (isset($message['sender_chat'])) {
        Logger::debug('Ignoring channel message', ['chat_id' => $chatId]);
        return;
    }

    // ═══════════════════════════════════════════
    // Forwarded message from channel (registration)
    // ═══════════════════════════════════════════
    if (isset($message['forward_from_chat']) && $message['forward_from_chat']['type'] === 'channel') {
        $forwardChat = $message['forward_from_chat'];
        $channelId = $forwardChat['id'];
        $channelUsername = $forwardChat['username'] ?? '';

        $db->execute(
            "INSERT INTO user_channels (chat_id, channel_id, channel_username, verified_at, is_active)
             VALUES (:chat_id, :channel_id, :username, :time, 1)
             ON CONFLICT(chat_id) DO UPDATE SET
                 channel_id = :channel_id2, channel_username = :username2, verified_at = :time2, is_active = 1",
            [
                'chat_id' => $chatId, 'channel_id' => (string) $channelId, 'channel_id2' => (string) $channelId,
                'username' => $channelUsername, 'username2' => $channelUsername,
                'time' => time(), 'time2' => time(),
            ]
        );

        $botId = explode(':', BALE_BOT_TOKEN)[0];
        $isAdmin = BaleNotifier::isBotAdmin($channelId, $botId);

        if ($isAdmin) {
            BaleNotifier::sendMessage($chatId,
                "✅ *کانال شما با موفقیت ثبت شد!*\n\n📢 کانال: @{$channelUsername}\n🛡️ ربات ادمین کانال شماست.\n\n🎥 حالا می‌توانید دانلود کنید.",
                BaleNotifier::startMenu()
            );
        } else {
            BaleNotifier::sendMessage($chatId,
                "⚠️ *کانال شناسایی شد اما ربات ادمین نیست!*\n\n📢 کانال: @{$channelUsername}\n\nلطفاً ربات را *ادمین* کانال کنید (دسترسی ارسال پیام).\nسپس دکمه بررسی وضعیت را بزنید.",
                ['inline_keyboard' => [[['text' => '🔄 بررسی ادمین بودن', 'callback_data' => 'check_admin']]]]
            );
        }
        return;
    }

    Logger::debug('Processing message', ['chat_id' => $chatId, 'text' => substr($text, 0, 100)]);

    // ═══════════════════════════════════════════
    // /start
    // ═══════════════════════════════════════════
    if (str_starts_with($text, '/start')) {
        $membership = BaleNotifier::getChatMember(FORCE_JOIN_CHANNEL_ID, $chatId);
        if (!$membership['is_member']) {
            BaleNotifier::sendMessage($chatId,
                "📢 *برای استفاده از ربات، ابتدا باید عضو کانال شوید!*\n\n🔸 روی دکمه زیر کلیک کنید و عضو کانال شوید.\n🔸 سپس دکمه *بررسی عضویت* را بزنید.",
                BaleNotifier::forceJoinKeyboard()
            );
            return;
        }

        $userChannel = $db->fetchOne("SELECT channel_id FROM user_channels WHERE chat_id = :chat_id AND is_active = 1", ['chat_id' => $chatId]);
        if (!$userChannel) {
            BaleNotifier::sendMessage($chatId,
                "📢 *برای ادامه، باید کانال آرشیو خود را معرفی کنید!*\n\n🔸 یک کانال در بله بسازید.\n🔸 ربات را *ادمین* کانال کنید.\n🔸 یک پیام از کانال برای ربات *Forward* کنید.\n\n📌 فایل‌های دانلودی در کانال شما آرشیو می‌شوند."
            );
            return;
        }

        clearUserState($chatId);
        BaleNotifier::sendMessage($chatId,
            "🎬 *سلام! به ربات دانلودر یوتیوب خوش آمدید!*\n\nکانال پشتیبانی: @GeminiPrompt\n\n👇 یکی از گزینه‌های زیر را انتخاب کنید:",
            BaleNotifier::startMenu()
        );
        return;
    }

    // ═══════════════════════════════════════════
    // /help
    // ═══════════════════════════════════════════
    if (str_starts_with($text, '/help')) {
        BaleNotifier::sendMessage($chatId,
            "📖 *راهنمای ربات*\n\n🔸 *دانلود ویدیو:* لینک یوتیوب را ارسال کنید\n🔸 *جستجو:* از منوی اصلی گزینه جستجو را بزنید\n🔸 *تنظیمات:* کیفیت و زیرنویس را تنظیم کنید\n🔸 *محدودیت:* هر ۵ دقیقه یک دانلود، هر ۶۰ ثانیه یک جستجو\n🔸 *صف:* در زمان شلوغی، درخواست شما در صف قرار می‌گیرد\n\n📊 *وضعیت سرور:* /status",
            BaleNotifier::startMenu()
        );
        return;
    }

    // ═══════════════════════════════════════════
    // /status
    // ═══════════════════════════════════════════
    if (str_starts_with($text, '/status')) {
        $remaining = $rateLimiter->getRemainingTimeFormatted($chatId);
        $dailyRemaining = $rateLimiter->getRemainingDailyRequests($chatId);
        $queueSize = $queueManager->getQueueSize();
        $userPending = $queueManager->getUserPendingCount($chatId);
        $searchRemaining = getSearchRemainingTime($chatId);

        $statusText = "📊 *وضعیت سرور*\n\n✅ *سرویس:* فعال\n⏱ *درخواست دانلود بعدی:* {$remaining}\n🔍 *جستجوی بعدی:* " . ($searchRemaining > 0 ? "{$searchRemaining} ثانیه دیگر" : "آماده ✅") . "\n📥 *دانلودهای باقی‌مانده امروز:* {$dailyRemaining}\n📋 *کارهای در صف:* {$queueSize}\n";
        if ($userPending > 0) $statusText .= "⏳ *درخواست‌های شما در صف:* {$userPending}\n";
        $statusText .= "\n💡 با ارسال لینک یوتیوب دانلود را شروع کنید.";
        BaleNotifier::sendMessage($chatId, $statusText, BaleNotifier::startMenu());
        return;
    }

    // ═══════════════════════════════════════════
    // CHECK USER STATE — highest priority after commands
    // ═══════════════════════════════════════════
    $userState = getUserState($chatId);

    // ├─ State: waiting_for_share_target
    if ($userState && $userState['state'] === 'waiting_for_share_target') {
        $pendingShare = $db->fetchOne("SELECT youtube_url, video_id FROM pending_shares WHERE chat_id = :chat_id", ['chat_id' => $chatId]);
        if ($pendingShare) {
            $targetChatId = trim($text);
            $videoLink = "https://www.youtube.com/watch?v={$pendingShare['video_id']}";
            $shareText = "🎬 *یک ویدیوی یوتیوب با شما به اشتراک گذاشته شد!*\n\n🔗 [مشاهده ویدیو در یوتیوب]({$videoLink})\n\n💡 _با ربات @khashayarbot می‌توانید این ویدیو را دانلود کنید._";

            $sent = BaleNotifier::sendMessage($targetChatId, $shareText);
            $db->execute("DELETE FROM pending_shares WHERE chat_id = :chat_id", ['chat_id' => $chatId]);
            clearUserState($chatId);

            if ($sent) {
                BaleNotifier::sendMessage($chatId, "✅ *ویدیو با موفقیت ارسال شد!*\n\n👤 مقصد: `{$targetChatId}`", BaleNotifier::startMenu());
            } else {
                BaleNotifier::sendMessage($chatId, "❌ *خطا در ارسال!*\n\nمطمئن شوید chat_id یا username مقصد درست باشد.\nهمچنین کاربر مقصد باید قبلاً به ربات پیام داده باشد.", BaleNotifier::startMenu());
            }
        } else {
            clearUserState($chatId);
            BaleNotifier::sendMessage($chatId, "⚠️ اطلاعات share منقضی شده است.", BaleNotifier::startMenu());
        }
        return;
    }

    // ├─ State: waiting_for_search_query
    if ($userState && $userState['state'] === 'waiting_for_search_query') {
        if (strlen($text) < 2) {
            BaleNotifier::sendMessage($chatId, "⚠️ عبارت جستجو باید حداقل ۲ کاراکتر باشد. دوباره تایپ کنید یا /start را بزنید.");
            return;
        }
        if (isSearchRateLimited($chatId)) {
            $remaining = getSearchRemainingTime($chatId);
            BaleNotifier::sendMessage($chatId, "⏳ *لطفاً کمی صبر کنید!*\n\n🔍 جستجوی بعدی: {$remaining} ثانیه دیگر", BaleNotifier::startMenu());
            clearUserState($chatId);
            return;
        }

        clearUserState($chatId);
        
        // فقط یه پیام "در حال جستجو" — بدون دکمه اضافی
        BaleNotifier::sendMessage($chatId, "🔍 *در حال جستجو برای:* `{$text}`\n\n⏳ لطفاً صبر کنید...");

        $result = $githubClient->dispatchSearchWithFilters($text, $chatId, '10', '1', 'relevance', 'any', 'any', 'false', 'video');
        if ($result['success']) {
            recordSearchRequest($chatId);
            // دیگه پیام "جستجو آغاز شد" نمی‌فرستیم — workflow خودش نتایج رو می‌فرسته
        } else {
            BaleNotifier::sendMessage($chatId, "❌ *خطا در جستجو!*\n\nکد خطا: {$result['http_code']}\n\nلطفاً دوباره تلاش کنید.", BaleNotifier::startMenu());
        }
        return;
    }

    // ├─ State: waiting_for_download_url
    if ($userState && $userState['state'] === 'waiting_for_download_url') {
        $youtubeUrls = extractYoutubeUrls($text);
        if (empty($youtubeUrls)) {
            BaleNotifier::sendMessage($chatId, "⚠️ لینک یوتیوب معتبر نیست. لطفاً یک لینک صحیح ارسال کنید یا /start را بزنید.");
            return;
        }

        $userChannel = $db->fetchOne("SELECT channel_id FROM user_channels WHERE chat_id = :chat_id AND is_active = 1", ['chat_id' => $chatId]);
        if (!$userChannel) {
            BaleNotifier::sendMessage($chatId, "📢 *شما هنوز کانال آرشیو خود را معرفی نکرده‌اید!*\n\n🔸 یک کانال در بله بسازید.\n🔸 ربات را *ادمین* کانال کنید.\n🔸 یک پیام از کانال برای ربات *Forward* کنید.");
            return;
        }
        if ($rateLimiter->isRateLimited($chatId)) { BaleNotifier::notifyRateLimited($chatId, $rateLimiter->getRemainingTime($chatId)); return; }
        if ($rateLimiter->isDailyLimitExceeded($chatId)) { BaleNotifier::notifyDailyLimitReached($chatId); return; }

        $settings = getUserSettings($chatId);
        $youtubeUrl = $youtubeUrls[0];
        clearUserState($chatId);

        $db->execute("INSERT OR REPLACE INTO pending_downloads (chat_id, youtube_url, quality, subtitles, created_at) VALUES (:chat_id, :url, :quality, :subs, :time)",
            ['chat_id' => $chatId, 'url' => $youtubeUrl, 'quality' => $settings['quality'], 'subs' => $settings['subtitles'], 'time' => time()]);

        $qualityName = QUALITY_MAP[$settings['quality']] ?? '✨ Best Quality';
        $subsStatus = $settings['subtitles'] === 'yes' ? '✅ فعال' : '❌ غیرفعال';
        $confirmText = "🎬 *آماده دانلود*\n\n🔗 `" . substr($youtubeUrl, 0, 50) . "...`\n🎬 کیفیت: {$qualityName}\n📝 زیرنویس: {$subsStatus}\n\nبرای شروع دکمه تأیید را بزنید:";
        BaleNotifier::sendMessage($chatId, $confirmText, BaleNotifier::confirmDownloadKeyboard($settings['quality'], $settings['subtitles'] === 'yes'));
        return;
    }

    // ═══════════════════════════════════════════
    // No active state — detect YouTube URL or fallback
    // ═══════════════════════════════════════════
    $youtubeUrls = extractYoutubeUrls($text);
    if (!empty($youtubeUrls)) {
        $userChannel = $db->fetchOne("SELECT channel_id FROM user_channels WHERE chat_id = :chat_id AND is_active = 1", ['chat_id' => $chatId]);
        if (!$userChannel) {
            BaleNotifier::sendMessage($chatId, "📢 *شما هنوز کانال آرشیو خود را معرفی نکرده‌اید!*\n\n🔸 یک کانال در بله بسازید.\n🔸 ربات را *ادمین* کانال کنید.\n🔸 یک پیام از کانال برای ربات *Forward* کنید.");
            return;
        }
        if ($rateLimiter->isRateLimited($chatId)) { BaleNotifier::notifyRateLimited($chatId, $rateLimiter->getRemainingTime($chatId)); return; }
        if ($rateLimiter->isDailyLimitExceeded($chatId)) { BaleNotifier::notifyDailyLimitReached($chatId); return; }

        $settings = getUserSettings($chatId);
        $youtubeUrl = $youtubeUrls[0];

        // ═══════════════ چک تکراری بودن ═══════════════
        $alreadyDownloaded = $db->fetchOne(
            "SELECT id, status, completed_at FROM pending_queue 
             WHERE chat_id = :chat_id AND youtube_url = :url 
             AND status IN ('completed', 'pending', 'dispatched')
             ORDER BY created_at DESC LIMIT 1",
            ['chat_id' => $chatId, 'url' => $youtubeUrl]
        );

        if ($alreadyDownloaded) {
            $db->execute("INSERT OR REPLACE INTO pending_downloads (chat_id, youtube_url, quality, subtitles, created_at) VALUES (:chat_id, :url, :quality, :subs, :time)",
                ['chat_id' => $chatId, 'url' => $youtubeUrl, 'quality' => $settings['quality'], 'subs' => $settings['subtitles'], 'time' => time()]);

            if ($alreadyDownloaded['status'] === 'completed') {
                $confirmText = "📋 *این فایل قبلاً دانلود شده است!*\n\n🔗 `" . substr($youtubeUrl, 0, 50) . "...`\n\n🎬 *می‌خواهید دوباره دانلود کنید؟*";
                $keyboard = ['inline_keyboard' => [[['text' => '✅ بله، دوباره دانلود کن', 'callback_data' => 'confirm_download'], ['text' => '❌ خیر', 'callback_data' => 'cancel_download']]]];
            } elseif ($alreadyDownloaded['status'] === 'pending') {
                $confirmText = "📋 *این فایل در صف دانلود شماست!*\n\n🔗 `" . substr($youtubeUrl, 0, 50) . "...`\n\n⏳ لطفاً صبر کنید تا دانلود کامل شود.";
                $keyboard = BaleNotifier::statusCheckKeyboard();
            } else {
                $confirmText = "📋 *این فایل در حال دانلود است!*\n\n🔗 `" . substr($youtubeUrl, 0, 50) . "...`\n\n🔄 لطفاً صبر کنید تا دانلود کامل شود.";
                $keyboard = BaleNotifier::statusCheckKeyboard();
            }
            BaleNotifier::sendMessage($chatId, $confirmText, $keyboard);
            return;
        }

        // لینک جدید — confirmation معمولی
        $db->execute("INSERT OR REPLACE INTO pending_downloads (chat_id, youtube_url, quality, subtitles, created_at) VALUES (:chat_id, :url, :quality, :subs, :time)",
            ['chat_id' => $chatId, 'url' => $youtubeUrl, 'quality' => $settings['quality'], 'subs' => $settings['subtitles'], 'time' => time()]);

        $qualityName = QUALITY_MAP[$settings['quality']] ?? '✨ Best Quality';
        $subsStatus = $settings['subtitles'] === 'yes' ? '✅ فعال' : '❌ غیرفعال';
        $confirmText = "🎬 *آماده دانلود*\n\n🔗 `" . substr($youtubeUrl, 0, 50) . "...`\n🎬 کیفیت: {$qualityName}\n📝 زیرنویس: {$subsStatus}\n\nبرای شروع دکمه تأیید را بزنید:";
        BaleNotifier::sendMessage($chatId, $confirmText, BaleNotifier::confirmDownloadKeyboard($settings['quality'], $settings['subtitles'] === 'yes'));
        return;
    }

    // ═══════════════════════════════════════════
    // Fallback — no URL, no state
    // ═══════════════════════════════════════════
    BaleNotifier::sendMessage($chatId, "📋 *لطفاً از دکمه‌های منو استفاده کنید.*\n\n🎥 برای دانلود، لینک یوتیوب ارسال کنید.\n🔍 برای جستجو، دکمه \"سرچ یوتوب\" را بزنید.", BaleNotifier::startMenu());
}

// ══════════════════════════════════════════════════════════
// Callback Query Processing
// ══════════════════════════════════════════════════════════

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

    Logger::debug('Processing callback', ['chat_id' => $chatId, 'data' => $data]);

    // ═══════════════════════════════════════════
    // Main Menu Handlers — edit current message
    // ═══════════════════════════════════════════

    // → Download button
    if ($data === 'menu_download') {
        BaleNotifier::answerCallback($callbackId);
        setUserState($chatId, 'waiting_for_download_url');
        BaleNotifier::editMessage($chatId, $messageId, "🔗 *لینک ویدیوی یوتیوب را ارسال کنید:*\n\n_مثال: https://youtu.be/abc123def45_", BaleNotifier::backToMainMenu());
        return;
    }

    // → Search button
    if ($data === 'menu_search') {
        BaleNotifier::answerCallback($callbackId);
        setUserState($chatId, 'waiting_for_search_query');
        BaleNotifier::editMessage($chatId, $messageId, "🔍 *جستجوی یوتیوب*\n\nلطفاً عبارت مورد نظر را وارد کنید:", BaleNotifier::backToMainMenu());
        return;
    }

    // → Settings button
    if ($data === 'menu_settings') {
        $settings = getUserSettings($chatId);
        $qualityName = QUALITY_MAP[$settings['quality']] ?? '✨ Best Quality';
        $subsStatus = $settings['subtitles'] === 'yes' ? '✅ فعال' : '❌ غیرفعال';
        BaleNotifier::answerCallback($callbackId);
        $settingsText = "⚙️ *تنظیمات فعلی:*\n\n🎬 *کیفیت:* {$qualityName}\n📝 *زیرنویس:* {$subsStatus}\n\nبرای تغییر روی گزینه مورد نظر کلیک کنید:";
        BaleNotifier::editMessage($chatId, $messageId, $settingsText, BaleNotifier::settingsMainMenu());
        return;
    }

    // → Help button
    if ($data === 'menu_help') {
        BaleNotifier::answerCallback($callbackId);
        BaleNotifier::editMessage($chatId, $messageId, "📖 *راهنما*\n\n🔸 لینک یوتیوب ارسال کنید → تأیید → دانلود\n🔸 تنظیم کیفیت و زیرنویس از منوی تنظیمات\n🔸 هر ۵ دقیقه یک دانلود، هر ۶۰ ثانیه یک جستجو\n🔸 در زمان شلوغی، درخواست در صف قرار می‌گیرد", BaleNotifier::backToMainMenu());
        return;
    }

    // → Status button
    if ($data === 'menu_status') {
        $remaining = $rateLimiter->getRemainingTimeFormatted($chatId);
        $queueSize = $queueManager->getQueueSize();
        $searchRemaining = getSearchRemainingTime($chatId);
        BaleNotifier::answerCallback($callbackId);
        BaleNotifier::editMessage($chatId, $messageId, "📊 *وضعیت سرور*\n\n✅ سرویس: فعال\n⏱ دانلود بعدی: {$remaining}\n🔍 جستجوی بعدی: " . ($searchRemaining > 0 ? "{$searchRemaining} ثانیه" : "آماده ✅") . "\n📋 کارهای در صف: {$queueSize}", BaleNotifier::backToMainMenu());
        return;
    }

    // → Update Channel
    if ($data === 'update_channel') {
        BaleNotifier::answerCallback($callbackId);
        BaleNotifier::editMessage($chatId, $messageId, "📢 *بروزرسانی کانال آرشیو*\n\nلطفاً یک پیام از کانال جدید خود را *Forward* کنید.", BaleNotifier::backToMainMenu());
        return;
    }

    // → Back to Main Menu
    if ($data === 'back_to_main') {
        BaleNotifier::answerCallback($callbackId);
        clearUserState($chatId);
        BaleNotifier::editMessage($chatId, $messageId, "🎬 *منوی اصلی*\n\n👇 یکی از گزینه‌های زیر را انتخاب کنید:", BaleNotifier::startMenu());
        return;
    }

    // ═══════════════════════════════════════════
    // Settings Submenus
    // ═══════════════════════════════════════════

    if ($data === 'settings_quality') {
        BaleNotifier::answerCallback($callbackId);
        BaleNotifier::editMessage($chatId, $messageId, "🎬 *کیفیت ویدیوی خود را انتخاب کنید:*", BaleNotifier::qualityMenu());
        return;
    }

    if ($data === 'settings_subs') {
        $settings = getUserSettings($chatId);
        BaleNotifier::answerCallback($callbackId);
        BaleNotifier::editMessage($chatId, $messageId, "📝 *تنظیمات زیرنویس:*", BaleNotifier::subtitleMenu($settings['subtitles'] === 'yes'));
        return;
    }

    if ($data === 'settings_main') {
        $settings = getUserSettings($chatId);
        $qualityName = QUALITY_MAP[$settings['quality']] ?? '✨ Best Quality';
        $subsStatus = $settings['subtitles'] === 'yes' ? '✅ فعال' : '❌ غیرفعال';
        BaleNotifier::answerCallback($callbackId);
        BaleNotifier::editMessage($chatId, $messageId, "⚙️ *تنظیمات فعلی:*\n\n🎬 *کیفیت:* {$qualityName}\n📝 *زیرنویس:* {$subsStatus}", BaleNotifier::settingsMainMenu());
        return;
    }

    if (str_starts_with($data, 'quality_')) {
        $quality = str_replace('quality_', '', $data);
        $settings = getUserSettings($chatId);
        saveUserSettings($chatId, $quality, $settings['subtitles']);
        BaleNotifier::answerCallback($callbackId, '✅ کیفیت تنظیم شد!');
        BaleNotifier::editMessage($chatId, $messageId, "🎬 *کیفیت ویدیوی خود را انتخاب کنید:*", BaleNotifier::qualityMenu());
        return;
    }

    if ($data === 'toggle_subs') {
        $settings = getUserSettings($chatId);
        $newSubs = $settings['subtitles'] === 'yes' ? 'no' : 'yes';
        saveUserSettings($chatId, $settings['quality'], $newSubs);
        BaleNotifier::answerCallback($callbackId, '✅ تنظیمات زیرنویس ذخیره شد!');
        BaleNotifier::editMessage($chatId, $messageId, "📝 *تنظیمات زیرنویس:*", BaleNotifier::subtitleMenu($newSubs === 'yes'));
        return;
    }

    if ($data === 'settings_close' || $data === 'settings_back') {
        BaleNotifier::answerCallback($callbackId);
        BaleNotifier::editMessage($chatId, $messageId, "⚙️ *تنظیمات بسته شد.*", BaleNotifier::backToMainMenu());
        return;
    }

    // ═══════════════════════════════════════════
    // Download Confirmation
    // ═══════════════════════════════════════════

    if ($data === 'confirm_download') {
        $pending = $db->fetchOne("SELECT youtube_url, quality, subtitles FROM pending_downloads WHERE chat_id = :chat_id", ['chat_id' => $chatId]);
        if (!$pending || !$pending['youtube_url']) {
            BaleNotifier::answerCallback($callbackId, '⚠️ لینک منقضی شده است. لطفاً دوباره ارسال کنید.', true);
            return;
        }
        $db->execute("DELETE FROM pending_downloads WHERE chat_id = :chat_id", ['chat_id' => $chatId]);
        BaleNotifier::answerCallback($callbackId, '🔄 در حال افزودن به صف...', false);
        BaleNotifier::editMessage($chatId, $messageId, "⏳ *در حال افزودن به صف دانلود...*");

        $db->execute("UPDATE pending_queue SET status = 'failed', error_message = 'User retried' WHERE chat_id = :chat_id AND status IN ('dispatched', 'pending')", ['chat_id' => $chatId]);

        $result = $queueManager->addToQueue($chatId, $pending['youtube_url'], $pending['quality'], $pending['subtitles'] === 'yes');
        if ($result['success']) {
            $rateLimiter->recordRequest($chatId);
            BaleNotifier::editMessage($chatId, $messageId, "✅ *به صف دانلود اضافه شد!*", BaleNotifier::statusCheckKeyboard());
            BaleNotifier::sendMessage($chatId, $result['message'], BaleNotifier::statusCheckKeyboard());
        } else {
            BaleNotifier::editMessage($chatId, $messageId, "❌ *خطا!*\n\n{$result['message']}", BaleNotifier::startMenu());
        }
        return;
    }

    if ($data === 'cancel_download') {
        $db->execute("DELETE FROM pending_downloads WHERE chat_id = :chat_id", ['chat_id' => $chatId]);
        BaleNotifier::answerCallback($callbackId, '❌ دانلود لغو شد.', false);
        BaleNotifier::editMessage($chatId, $messageId, "❌ *دانلود لغو شد.*", BaleNotifier::startMenu());
        return;
    }

    // ═══════════════════════════════════════════
    // Check Status
    // ═══════════════════════════════════════════

    if ($data === 'check_status') {
        BaleNotifier::answerCallback($callbackId, '🔍 در حال بررسی...', false);
        $jobStatus = $queueManager->getUserJobStatus($chatId);
        if (!$jobStatus) {
            BaleNotifier::editMessage($chatId, $messageId, "📋 *شما درخواست فعالی ندارید.*\n\nبرای دانلود جدید لینک ارسال کنید.", BaleNotifier::startMenu());
            return;
        }
        $statusMap = ['pending' => '⏳ در صف انتظار', 'dispatched' => '🔄 در حال دانلود', 'completed' => '✅ کامل شده', 'failed' => '❌ ناموفق'];
        $statusText = $statusMap[$jobStatus['status']] ?? '⏳ نامشخص';
        $replyText = "📊 *وضعیت درخواست شما*\n\n📌 وضعیت: {$statusText}\n";
        if ($jobStatus['status'] === 'pending') {
            $position = $queueManager->getQueuePosition((int) $jobStatus['id']);
            $wait = $queueManager->estimateWaitTime($position);
            $replyText .= "🔢 موقعیت در صف: {$position}\n⏱ زمان تقریبی: " . BaleNotifier::formatWaitTime($wait) . "\n";
        }
        if ($jobStatus['status'] === 'failed' && $jobStatus['error_message']) $replyText .= "⚠️ خطا: {$jobStatus['error_message']}\n";

        $triggerCooldown = 120;
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
        BaleNotifier::editMessage($chatId, $messageId, $replyText, BaleNotifier::statusCheckKeyboard());
        $popupText = "📌 {$statusText}";
        if ($jobStatus['status'] === 'pending') $popupText .= "\n🔢 صف: {$position}\n⏱ " . BaleNotifier::formatWaitTime($wait);
        BaleNotifier::answerCallback($callbackId, $popupText, true);
        return;
    }

    // ═══════════════════════════════════════════
    // Search Results: Download Video (dl_)
    // ═══════════════════════════════════════════

    if (str_starts_with($data, 'dl_')) {
        $videoId = str_replace('dl_', '', $data);
        $youtubeUrl = "https://www.youtube.com/watch?v={$videoId}";
        BaleNotifier::answerCallback($callbackId, '🔄 در حال بررسی...', false);
        if ($rateLimiter->isRateLimited($chatId)) { BaleNotifier::notifyRateLimited($chatId, $rateLimiter->getRemainingTime($chatId)); return; }
        if ($rateLimiter->isDailyLimitExceeded($chatId)) { BaleNotifier::notifyDailyLimitReached($chatId); return; }
        $settings = getUserSettings($chatId);
        $result = $queueManager->addToQueue($chatId, $youtubeUrl, $settings['quality'], $settings['subtitles'] === 'yes');
        if ($result['success']) {
            $rateLimiter->recordRequest($chatId);
            BaleNotifier::editMessage($chatId, $messageId, "✅ *به صف دانلود اضافه شد!*\n\n🎬 کیفیت: " . (QUALITY_MAP[$settings['quality']] ?? 'Best'));
            BaleNotifier::sendMessage($chatId, $result['message'], BaleNotifier::statusCheckKeyboard());
        } else {
            BaleNotifier::editMessage($chatId, $messageId, "❌ *خطا!*\n\n{$result['message']}");
        }
        return;
    }

    // ═══════════════════════════════════════════
    // Search Results: Download Audio (dla_)
    // ═══════════════════════════════════════════

    if (str_starts_with($data, 'dla_')) {
        $videoId = str_replace('dla_', '', $data);
        $youtubeUrl = "https://www.youtube.com/watch?v={$videoId}";
        BaleNotifier::answerCallback($callbackId, '🎵 در حال افزودن دانلود صدا...', false);
        if ($rateLimiter->isRateLimited($chatId)) { BaleNotifier::notifyRateLimited($chatId, $rateLimiter->getRemainingTime($chatId)); return; }
        if ($rateLimiter->isDailyLimitExceeded($chatId)) { BaleNotifier::notifyDailyLimitReached($chatId); return; }
        $result = $queueManager->addToQueue($chatId, $youtubeUrl, 'audio', false);
        if ($result['success']) {
            $rateLimiter->recordRequest($chatId);
            BaleNotifier::editMessage($chatId, $messageId, "✅ *دانلود صدا به صف اضافه شد!*\n\n🎵 کیفیت: Audio Only (MP3)");
            BaleNotifier::sendMessage($chatId, $result['message'], BaleNotifier::statusCheckKeyboard());
        } else {
            BaleNotifier::editMessage($chatId, $messageId, "❌ *خطا!*\n\n{$result['message']}");
        }
        return;
    }

    // ═══════════════════════════════════════════
    // Search Results: Share (share_)
    // ═══════════════════════════════════════════

    if (str_starts_with($data, 'share_')) {
        $videoId = str_replace('share_', '', $data);
        $youtubeUrl = "https://www.youtube.com/watch?v={$videoId}";
        BaleNotifier::answerCallback($callbackId, '📤 لطفاً chat_id مقصد را وارد کنید...', false);
        setUserState($chatId, 'waiting_for_share_target');
        $db->execute("INSERT OR REPLACE INTO pending_shares (chat_id, youtube_url, video_id, created_at) VALUES (:chat_id, :url, :vid, :time)",
            ['chat_id' => $chatId, 'url' => $youtubeUrl, 'vid' => $videoId, 'time' => time()]);
        BaleNotifier::editMessage($chatId, $messageId, "📤 *ارسال ویدیو به دوست*\n\n🔗 لینک: `{$youtubeUrl}`\n\n👤 *لطفاً chat_id یا username مقصد را وارد کنید:*\n_مثال: @username یا 123456789_", BaleNotifier::backToMainMenu());
        return;
    }

    // ═══════════════════════════════════════════
    // Search Pagination (sp_)
    // ═══════════════════════════════════════════

    if (str_starts_with($data, 'sp_')) {
        BaleNotifier::answerCallback($callbackId, '🔄 در حال بارگذاری صفحه...', false);
        $parts = explode('_', $data);
        // sp, PAGE, QSHORT, MAX, SORT, DUR, DATE, LIVE, TYPE
        if (count($parts) < 9) {
            BaleNotifier::answerCallback($callbackId, '⚠️ داده‌های pagination ناقص است.', true);
            return;
        }
        $page = $parts[1];
        $maxResults = $parts[3];
        $sortBy = $parts[4];
        $durationFilter = $parts[5];
        $dateFilter = $parts[6];
        $liveFilter = $parts[7];
        $searchType = $parts[8];

        // بازسازی query
        $currentCaption = $callbackQuery['message']['caption'] ?? $callbackQuery['message']['text'] ?? '';
        preg_match('/عبارت:\s*`([^`]+)`/u', $currentCaption, $matches);
        $query = $matches[1] ?? '';
        if (empty($query)) {
            BaleNotifier::answerCallback($callbackId, '⚠️ نمی‌توان query را بازیابی کرد.', true);
            return;
        }

        $result = $githubClient->dispatchSearchWithFilters($query, $chatId, $maxResults, $page, $sortBy, $durationFilter, $dateFilter, $liveFilter, $searchType);
        if ($result['success']) {
            BaleNotifier::editMessage($chatId, $messageId, "🔄 *در حال بارگذاری صفحه {$page}...*");
        } else {
            BaleNotifier::answerCallback($callbackId, '❌ خطا در بارگذاری صفحه. دوباره تلاش کنید.', true);
        }
        return;
    }

    // ═══════════════════════════════════════════
    // Force Join & Channel Admin Checks
    // ═══════════════════════════════════════════

    if ($data === 'check_join') {
        $membership = BaleNotifier::getChatMember(FORCE_JOIN_CHANNEL_ID, $chatId);
        if ($membership['is_member']) {
            BaleNotifier::answerCallback($callbackId, '✅ عضویت شما تأیید شد!', false);
            $userChannel = $db->fetchOne("SELECT channel_id FROM user_channels WHERE chat_id = :chat_id AND is_active = 1", ['chat_id' => $chatId]);
            if (!$userChannel) {
                BaleNotifier::editMessage($chatId, $messageId, "✅ *عضویت شما تأیید شد!*\n\n📢 حالا باید کانال آرشیو خود را معرفی کنید:\n🔸 یک کانال بسازید\n🔸 ربات را ادمین کنید\n🔸 یک پیام از کانال Forward کنید");
            } else {
                BaleNotifier::editMessage($chatId, $messageId, "✅ *عضویت شما تأیید شد!*\n\n🎬 *به ربات خوش آمدید!*", BaleNotifier::startMenu());
            }
        } else {
            BaleNotifier::answerCallback($callbackId, '❌ هنوز عضو نشده‌اید!', true);
        }
        return;
    }

    if ($data === 'check_admin') {
        $userChannel = $db->fetchOne("SELECT channel_id FROM user_channels WHERE chat_id = :chat_id AND is_active = 1", ['chat_id' => $chatId]);
        if (!$userChannel) { BaleNotifier::answerCallback($callbackId, '⚠️ ابتدا کانال خود را معرفی کنید.', true); return; }
        $botId = explode(':', BALE_BOT_TOKEN)[0];
        $isAdmin = BaleNotifier::isBotAdmin($userChannel['channel_id'], $botId);
        if ($isAdmin) {
            BaleNotifier::answerCallback($callbackId, '✅ ربات ادمین کانال شماست!', false);
            BaleNotifier::editMessage($chatId, $messageId, "✅ *ربات ادمین کانال شماست!*\n\nحالا می‌توانید دانلود کنید.", BaleNotifier::startMenu());
        } else {
            BaleNotifier::answerCallback($callbackId, '❌ ربات هنوز ادمین نیست!', true);
            BaleNotifier::editMessage($chatId, $messageId, "❌ *ربات ادمین کانال شما نیست!*\n\nلطفاً ربات را ادمین کانال کنید.");
        }
        return;
    }

    // ═══════════════════════════════════════════
    // Unknown Callback
    // ═══════════════════════════════════════════
    BaleNotifier::answerCallback($callbackId);
    Logger::debug('Unknown callback data', ['data' => $data]);
}

// ══════════════════════════════════════════════════════════
// Main Router
// ══════════════════════════════════════════════════════════

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = json_decode(file_get_contents('php://input'), true);

if ($requestMethod === 'POST' && $input && isset($input['update_id'])) {
    $updateId = $input['update_id'];
    $alreadyProcessed = $db->fetchValue("SELECT update_id FROM processed_updates WHERE update_id = :update_id", ['update_id' => $updateId]);
    if ($alreadyProcessed) {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
    $db->execute("INSERT INTO processed_updates (update_id, processed_at) VALUES (:update_id, :time)", ['update_id' => $updateId, 'time' => time()]);
    try {
        if (isset($input['message'])) {
            processMessage($input['message']);
        } elseif (isset($input['callback_query'])) {
            processCallbackQuery($input['callback_query']);
        }
    } catch (\Exception $e) {
        Logger::exception($e, 'Error processing update', ['update_id' => $updateId]);
        error_log('[Gateway] Update error: ' . $e->getMessage());
    }
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

if ($requestMethod === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html dir='rtl' lang='fa'><head><meta charset='UTF-8'><title>Gateway</title></head><body>";
    echo "<h1>✅ Bale YouTube Downloader Gateway v6.0.0</h1>";
    echo "<p>Gateway is running!</p><hr><p><strong>نسخه:</strong> ۶.۰ | <strong>تاریخ:</strong> اردیبهشت ۱۴۰۵</p></body></html>";
    exit;
}

http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['ok' => false, 'error' => 'Invalid request method or body']);
