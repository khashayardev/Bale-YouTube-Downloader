<?php
/**
 * ============================================================
 * BaleNotifier.php — Bale Bot Notification System
 * ============================================================
 * 
 * 🏗️ Architecture Layer: 1 - Core Services
 * 📦 Part of: Khashayar YouTube Downloader - Queue System
 * 🔗 Dependency: Config.php (File 1) ✅, Logger.php (File 2) ✅
 * 
 * 🎯 Purpose:
 *   • Unified interface for all Bale Bot API interactions
 *   • Message sending with Markdown formatting
 *   • Document/file sending with progress tracking
 *   • Inline keyboard management (menus, confirmations, file downloads)
 *   • Callback query handling with alert support
 *   • Automatic retry on transient failures
 *   • Rate limit awareness for API calls
 * 
 * 🔄 Extracted from: gateway.php (callBaleAPI, sendMessage, sendDocument, 
 *    editMessageText, answerCallbackQuery — completely refactored)
 * 
 * 🔒 Design Decisions:
 *   • Static methods for simplicity (stateless API calls)
 *   • Automatic retry on 429/5xx errors
 *   • Persian message templates built-in
 *   • File size validation before sending
 *   • Callback data safety (length validation)
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

// Guard: Must be loaded after Config.php
if (!defined('CONFIG_LOADED')) {
    die('⛔ BaleNotifier requires Config.php to be loaded first.');
}

/**
 * ============================================================
 * BaleNotifier Class
 * ============================================================
 */
class BaleNotifier
{
    /** @var string Base URL for Bale API */
    private static string $apiBase;

    /** @var int HTTP timeout for API calls */
    private static int $timeout;

    /** @var int Maximum retries for failed API calls */
    private static int $maxRetries;

    /** @var bool Initialization flag */
    private static bool $initialized = false;

    /**
     * Initialize notifier with configuration
     * Called automatically on first use
     * 
     * @return void
     */
    private static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$apiBase = BALE_API_BASE;
        self::$timeout = defined('BALE_API_TIMEOUT') ? BALE_API_TIMEOUT : 10;
        self::$maxRetries = defined('BALE_API_MAX_RETRIES') ? BALE_API_MAX_RETRIES : 2;
        self::$initialized = true;
    }

    /**
     * ============================================================
     * Core API Communication
     * ============================================================
     */

    /**
     * Make a call to Bale Bot API
     * 
     * @param string $method API method name
     * @param array<string, mixed> $params Request parameters
     * @return array{http_code: int, body: array|null} Response data
     */
    public static function callAPI(string $method, array $params = []): array
    {
        self::init();

        $url = self::$apiBase . '/' . $method;
        $attempt = 0;

        while ($attempt <= self::$maxRetries) {
            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => self::$timeout,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            ]);

            // Check if any parameter is a CURLFile (file upload)
            $hasFile = false;
            foreach ($params as $value) {
                if ($value instanceof CURLFile) {
                    $hasFile = true;
                    break;
                }
            }

            if ($hasFile) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            } else {
                $jsonBody = json_encode($params, JSON_UNESCAPED_UNICODE);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($jsonBody),
                ]);
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // Success
            if ($httpCode >= 200 && $httpCode < 300) {
                $body = json_decode($response, true);
                return [
                    'http_code' => $httpCode,
                    'body'      => $body,
                ];
            }

            // Rate limited — wait and retry
            if ($httpCode === 429) {
                $retryAfter = 1;
                if ($response) {
                    $data = json_decode($response, true);
                    $retryAfter = $data['parameters']['retry_after'] ?? 1;
                }
                Logger::warning('Bale API rate limited', [
                    'method'    => $method,
                    'retry_after' => $retryAfter,
                    'attempt'   => $attempt + 1,
                ]);
                sleep($retryAfter);
                $attempt++;
                continue;
            }

            // Server error — retry
            if ($httpCode >= 500) {
                Logger::warning('Bale API server error', [
                    'method'     => $method,
                    'http_code'  => $httpCode,
                    'attempt'    => $attempt + 1,
                ]);
                sleep($attempt + 1); // Exponential backoff
                $attempt++;
                continue;
            }

            // Client error or network failure — don't retry
            Logger::error('Bale API call failed', [
                'method'     => $method,
                'http_code'  => $httpCode,
                'error'      => $curlError ?: 'Unknown error',
            ]);

            return [
                'http_code' => $httpCode,
                'body'      => $response ? json_decode($response, true) : null,
            ];
        }

        // All retries exhausted
        Logger::error('Bale API call failed after retries', [
            'method'  => $method,
            'attempts' => $attempt,
        ]);

        return [
            'http_code' => 0,
            'body'      => null,
        ];
    }

    /**
     * Check if API response indicates success
     * 
     * @param array{http_code: int, body: array|null} $response API response
     * @return bool True if successful
     */
    private static function isSuccess(array $response): bool
    {
        if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
            return false;
        }

        if ($response['body'] === null) {
            return false;
        }

        return ($response['body']['ok'] ?? false) === true;
    }

    /**
     * ============================================================
     * Message Methods
     * ============================================================
     */

    /**
     * Send a text message to a chat
     * 
     * @param string|int $chatId Target chat ID
     * @param string $text Message text (Markdown supported)
     * @param array|null $replyMarkup Inline keyboard or reply keyboard
     * @return bool True if sent successfully
     */
    public static function sendMessage(string|int $chatId, string $text, ?array $replyMarkup = null): bool
    {
        $params = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }

        $response = self::callAPI('sendMessage', $params);

        if (!self::isSuccess($response)) {
            Logger::error('Failed to send message', [
                'chat_id' => $chatId,
                'text'    => substr($text, 0, 100),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Send a document/file to a chat
     * 
     * @param string|int $chatId Target chat ID
     * @param string $filePath Full path to file
     * @param string $caption Optional caption
     * @param array|null $replyMarkup Optional inline keyboard
     * @return bool True if sent successfully
     */
    public static function sendDocument(
        string|int $chatId, 
        string $filePath, 
        string $caption = '', 
        ?array $replyMarkup = null
    ): bool {
        // Validate file exists
        if (!file_exists($filePath)) {
            Logger::error('Document not found', ['path' => $filePath]);
            return false;
        }

        // Validate file size (Bale limit: 50MB)
        $fileSize = filesize($filePath);
        if ($fileSize > BALE_MAX_FILE_SIZE) {
            Logger::warning('File too large for direct send', [
                'path'      => basename($filePath),
                'size_mb'   => round($fileSize / 1024 / 1024, 2),
            ]);
            return false;
        }

        $params = [
            'chat_id'  => $chatId,
            'document' => new CURLFile($filePath),
            'caption'  => $caption,
            'parse_mode' => 'Markdown',
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        }

        $response = self::callAPI('sendDocument', $params);

        if (!self::isSuccess($response)) {
            Logger::error('Failed to send document', [
                'chat_id' => $chatId,
                'file'    => basename($filePath),
            ]);
            return false;
        }

        Logger::info('Document sent successfully', [
            'chat_id' => $chatId,
            'file'    => basename($filePath),
            'size_mb' => round($fileSize / 1024 / 1024, 2),
        ]);

        return true;
    }

    /**
     * Send a file using file_id (from channel archive)
     * 
     * @param string|int $chatId Target chat ID
     * @param string $fileId Bale file ID
     * @param string $caption Optional caption
     * @return bool True if sent successfully
     */
    public static function sendFileById(string|int $chatId, string $fileId, string $caption = ''): bool
    {
        $params = [
            'chat_id'  => $chatId,
            'document' => $fileId,
            'caption'  => $caption,
            'parse_mode' => 'Markdown',
        ];

        $response = self::callAPI('sendDocument', $params);

        if (!self::isSuccess($response)) {
            Logger::error('Failed to send file by ID', [
                'chat_id'  => $chatId,
                'file_id'  => substr($fileId, 0, 20) . '...',
            ]);
            return false;
        }

        return true;
    }

    /**
     * Edit an existing message text
     * 
     * @param string|int $chatId Chat ID
     * @param int $messageId Message ID to edit
     * @param string $text New text
     * @param array|null $replyMarkup New inline keyboard (optional)
     * @return bool True if edited successfully
     */
    public static function editMessage(
        string|int $chatId, 
        int $messageId, 
        string $text, 
        ?array $replyMarkup = null
    ): bool {
        $params = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }

        $response = self::callAPI('editMessageText', $params);

        if (!self::isSuccess($response)) {
            // Message might be too old to edit — don't log as error
            Logger::debug('Failed to edit message', [
                'chat_id'    => $chatId,
                'message_id' => $messageId,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Answer a callback query (dismiss loading indicator)
     * 
     * @param string $callbackQueryId Callback query ID
     * @param string $text Alert text (optional)
     * @param bool $showAlert Show as alert popup
     * @return bool True if answered successfully
     */
    public static function answerCallback(
        string $callbackQueryId, 
        string $text = '', 
        bool $showAlert = false
    ): bool {
        $params = [
            'callback_query_id' => $callbackQueryId,
            'text'              => $text,
            'show_alert'        => $showAlert,
        ];

        $response = self::callAPI('answerCallbackQuery', $params);

        if (!self::isSuccess($response)) {
            Logger::debug('Failed to answer callback', [
                'callback_id' => substr($callbackQueryId, 0, 20) . '...',
            ]);
            return false;
        }

        return true;
    }

    /**
     * Delete a message
     * 
     * @param string|int $chatId Chat ID
     * @param int $messageId Message ID to delete
     * @return bool True if deleted
     */
    public static function deleteMessage(string|int $chatId, int $messageId): bool
    {
        $response = self::callAPI('deleteMessage', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
        ]);

        return self::isSuccess($response);
    }

    /**
     * Check if user is member/admin of a channel
     * Tries getChatMember first, falls back to getChatAdministrators
     * 
     * @param string $channelUsername Channel username (e.g. @GeminiPrompt)
     * @param string|int $userId User ID to check
     * @return array{is_member: bool, status: string}
     */
    public static function getChatMember(string $channelUsername, string|int $userId): array
    {
        // Method 1: Try getChatMember (works if bot is member)
        $response = self::callAPI('getChatMember', [
            'chat_id' => $channelUsername,
            'user_id' => $userId,
        ]);

        if (self::isSuccess($response)) {
            $status = $response['body']['result']['status'] ?? 'unknown';
            $isMember = in_array($status, ['member', 'administrator', 'creator']);
            return ['is_member' => $isMember, 'status' => $status];
        }

        // Method 2: Fallback — check administrators list
        // (works if bot is admin but not member)
        $admins = self::getChatAdministrators($channelUsername);
        
        foreach ($admins as $admin) {
            $adminId = $admin['user']['id'] ?? null;
            if ($adminId && (string) $adminId === (string) $userId) {
                return ['is_member' => true, 'status' => 'administrator'];
            }
        }

        Logger::debug('Failed to verify membership', [
            'channel' => $channelUsername,
            'user_id' => $userId,
        ]);
        
        return ['is_member' => false, 'status' => 'unknown'];
    }

    /**
     * Get list of administrators in a channel
     * 
     * @param string|int $chatId Channel ID or username
     * @return array<int, array> Array of admin user objects
     */
    public static function getChatAdministrators(string|int $chatId): array
    {
        $response = self::callAPI('getChatAdministrators', [
            'chat_id' => $chatId,
        ]);

        if (!self::isSuccess($response)) {
            Logger::debug('Failed to get chat administrators', [
                'chat_id' => $chatId,
            ]);
            return [];
        }

        return $response['body']['result'] ?? [];
    }

    /**
     * Check if a user is admin in a channel
     * 
     * @param string|int $channelId Channel ID or username
     * @param string|int $botUserId Bot's user ID
     * @return bool True if bot is admin
     */
    public static function isBotAdmin(string|int $channelId, string|int $botUserId): bool
    {
        $admins = self::getChatAdministrators($channelId);

        foreach ($admins as $admin) {
            $adminId = $admin['user']['id'] ?? null;
            if ($adminId && (string) $adminId === (string) $botUserId) {
                return true;
            }
        }

        return false;
    }

    /**
     * ============================================================
     * Keyboard Builders
     * ============================================================
     */

    /**
     * Create main menu keyboard (persistent reply keyboard)
     * 
     * @return array<string, mixed> Reply keyboard markup
     */
    public static function mainMenuKeyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => '📥 دانلودر یوتوب'], ['text' => '🔍 سرچ یوتوب']],
                [['text' => '⚙️ تنظیمات'], ['text' => 'ℹ️ راهنما']],
                [['text' => '📊 وضعیت سرور'], ['text' => '🔄 بروزرسانی کانال']],
            ],
            'resize_keyboard' => true,
            'persistent'      => true,
        ];
    }

    /**
     * Create force join keyboard
     * 
     * @return array<string, mixed> Inline keyboard markup
     */
    public static function forceJoinKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '📢 عضویت در کانال', 'url' => FORCE_JOIN_CHANNEL_URL]],
                [['text' => '✅ بررسی عضویت', 'callback_data' => 'check_join']],
            ],
        ];
    }

    /**
     * Create update channel keyboard
     * 
     * @return array<string, mixed> Inline keyboard markup
     */
    public static function updateChannelKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '🔄 بروزرسانی کانال آرشیو', 'callback_data' => 'update_channel']],
            ],
        ];
    }


    /**
     * Create inline start menu
     * 
     * @return array<string, mixed> Inline keyboard markup
     */
    public static function startMenu(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '📥 دانلودر یوتوب', 'callback_data' => 'menu_download'],
                    ['text' => '🔍 سرچ یوتوب', 'callback_data' => 'menu_search'],
                ],
                [
                    ['text' => '⚙️ تنظیمات', 'callback_data' => 'menu_settings'],
                    ['text' => 'ℹ️ راهنما', 'callback_data' => 'menu_help'],
                ],
                [
                    ['text' => '📊 وضعیت سرور', 'callback_data' => 'menu_status'],
                    ['text' => '🔄 بروزرسانی کانال', 'callback_data' => 'update_channel'],
                ],
            ],
        ];
    }

    /**
     * Create a simple "Back to Main Menu" button
     * Used in all submenus for easy navigation
     * 
     * @return array<string, mixed> Inline keyboard markup
     */
    public static function backToMainMenu(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'back_to_main']],
            ],
        ];
    }

    /**
     * Create quality selection menu
     * 
     * @return array<string, mixed> Inline keyboard markup
     */
    public static function qualityMenu(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '✨ Best Quality', 'callback_data' => 'quality_best']],
                [
                    ['text' => '4K (2160p)', 'callback_data' => 'quality_2160'],
                    ['text' => '2K (1440p)', 'callback_data' => 'quality_1440'],
                ],
                [
                    ['text' => '1080p', 'callback_data' => 'quality_1080'],
                    ['text' => '720p', 'callback_data' => 'quality_720'],
                ],
                [
                    ['text' => '480p', 'callback_data' => 'quality_480'],
                    ['text' => '🎵 Audio Only', 'callback_data' => 'quality_audio'],
                ],
                [['text' => '🔙 بازگشت', 'callback_data' => 'settings_back']],
            ],
        ];
    }

    /**
     * Create settings main menu
     * 
     * @return array<string, mixed> Inline keyboard markup
     */
    public static function settingsMainMenu(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '🎬 کیفیت ویدیو', 'callback_data' => 'settings_quality']],
                [['text' => '📝 تنظیمات زیرنویس', 'callback_data' => 'settings_subs']],
                [['text' => '🔙 بستن منو', 'callback_data' => 'settings_close']],
            ],
        ];
    }

    /**
     * Create subtitle settings menu
     * 
     * @param bool $currentlyEnabled Current subtitle state
     * @return array<string, mixed> Inline keyboard markup
     */
    public static function subtitleMenu(bool $currentlyEnabled): array
    {
        $status = $currentlyEnabled ? '✅ فعال' : '❌ غیرفعال';
        
        return [
            'inline_keyboard' => [
                [['text' => "زیرنویس: {$status}", 'callback_data' => 'toggle_subs']],
                [['text' => '🔙 بازگشت به تنظیمات', 'callback_data' => 'settings_main']],
            ],
        ];
    }

    /**
     * Create download confirmation keyboard
     * 
     * @param string $quality Current quality setting
     * @param bool $subtitles Whether subtitles are enabled
     * @return array<string, mixed> Inline keyboard markup
     */
    public static function confirmDownloadKeyboard(string $quality, bool $subtitles): array
    {
        $qualityName = QUALITY_MAP[$quality] ?? '✨ Best Quality';
        $subsStatus = $subtitles ? '✅ فعال' : '❌ غیرفعال';
        
        return [
            'inline_keyboard' => [
                [['text' => '✅ تأیید و شروع دانلود', 'callback_data' => 'confirm_download']],
                [['text' => '❌ لغو', 'callback_data' => 'cancel_download']],
                [['text' => '⚙️ تغییر تنظیمات', 'callback_data' => 'menu_settings']],
            ],
        ];
    }

    /**
     * Create status check keyboard (after download started)
     * 
     * @return array<string, mixed> Inline keyboard markup
     */
    public static function statusCheckKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '🔄 بررسی وضعیت', 'callback_data' => 'check_status']],
            ],
        ];
    }

    /**
     * Create file download keyboard from file IDs
     * 
     * @param array<int, array{text: string, file_id: string}> $files File buttons
     * @return array<string, mixed> Inline keyboard markup
     */
    public static function fileDownloadKeyboard(array $files): array
    {
        $rows = [];
        $currentRow = [];

        foreach ($files as $index => $file) {
            $currentRow[] = [
                'text'          => $file['text'],
                'callback_data' => 'file_id_' . $file['file_id'],
            ];

            // 2 buttons per row
            if (count($currentRow) >= 2) {
                $rows[] = $currentRow;
                $currentRow = [];
            }
        }

        // Add remaining button
        if (!empty($currentRow)) {
            $rows[] = $currentRow;
        }

        return ['inline_keyboard' => $rows];
    }

    /**
     * ============================================================
     * Pre-built Notification Templates
     * ============================================================
     */

    /**
     * Notify user they are in queue
     * 
     * @param string|int $chatId User's chat ID
     * @param int $position Queue position
     * @param int $estimatedWaitSeconds Estimated wait time
     * @return bool True if sent
     */
    public static function notifyQueuePosition(string|int $chatId, int $position, int $estimatedWaitSeconds): bool
    {
        $waitStr = self::formatWaitTime($estimatedWaitSeconds);
        
        $text = "⏳ *شما در صف هستید!*\n\n";
        $text .= "🔢 موقعیت: `{$position}`\n";
        $text .= "⏱ زمان تقریبی: {$waitStr}\n\n";
        $text .= "📊 *وضعیت خودکار بروزرسانی می‌شود.*\n";
        $text .= "💡 پس از شروع دانلود به شما اطلاع می‌دهم.";

        return self::sendMessage($chatId, $text);
    }

    /**
     * Notify user their download has started
     * 
     * @param string|int $chatId User's chat ID
     * @param string $youtubeUrl YouTube URL being downloaded
     * @return bool True if sent
     */
    public static function notifyDownloadStarted(string|int $chatId, string $youtubeUrl): bool
    {
        $text = "🚀 *دانلود شما شروع شد!*\n\n";
        $text .= "🔗 `" . substr($youtubeUrl, 0, 50) . "...`\n\n";
        $text .= "⏱ *زمان تقریبی:* ۲ تا ۵ دقیقه\n\n";
        $text .= "👇 بعد از اتمام، دکمه بررسی وضعیت را بزنید:";

        return self::sendMessage($chatId, $text, self::statusCheckKeyboard());
    }

    /**
     * Notify user their download is complete (with file)
     * 
     * @param string|int $chatId User's chat ID
     * @param string $fileName Downloaded file name
     * @param int $fileSizeMB File size in MB
     * @param array|null $downloadKeyboard File download buttons
     * @return bool True if sent
     */
    public static function notifyDownloadComplete(
        string|int $chatId, 
        string $fileName, 
        int $fileSizeMB,
        ?array $downloadKeyboard = null
    ): bool {
        $text = "✅ *فایل شما آماده است!*\n\n";
        $text .= "📁 *نام:* `{$fileName}`\n";
        $text .= "📊 *حجم:* {$fileSizeMB}MB\n\n";
        $text .= "⚠️ *فایل تا ۵ دقیقه در سرور باقی می‌ماند.*\n";
        $text .= "👇 برای دریافت روی دکمه زیر کلیک کنید:";

        return self::sendMessage($chatId, $text, $downloadKeyboard);
    }

    /**
     * Notify user their download failed
     * 
     * @param string|int $chatId User's chat ID
     * @param string $reason Failure reason
     * @return bool True if sent
     */
    public static function notifyDownloadFailed(string|int $chatId, string $reason): bool
    {
        $text = "❌ *دانلود ناموفق بود*\n\n";
        $text .= "⚠️ *علت:* {$reason}\n\n";
        $text .= "🔄 لطفاً چند دقیقه دیگر دوباره تلاش کنید.\n";
        $text .= "💡 اگر مشکل ادامه داشت، کیفیت پایین‌تر را امتحان کنید.";

        return self::sendMessage($chatId, $text);
    }

    /**
     * Notify user about rate limit
     * 
     * @param string|int $chatId User's chat ID
     * @param int $remainingSeconds Seconds remaining
     * @return bool True if sent
     */
    public static function notifyRateLimited(string|int $chatId, int $remainingSeconds): bool
    {
        $waitStr = self::formatWaitTime($remainingSeconds);
        
        $text = "⏳ *لطفاً کمی صبر کنید!*\n\n";
        $text .= "⏱ *زمان باقی‌مانده:* {$waitStr}\n\n";
        $text .= "💡 این محدودیت برای استفاده منصفانه از سرویس است.";

        return self::sendMessage($chatId, $text);
    }

    /**
     * Notify user about daily limit reached
     * 
     * @param string|int $chatId User's chat ID
     * @return bool True if sent
     */
    public static function notifyDailyLimitReached(string|int $chatId): bool
    {
        $text = "📊 *محدودیت روزانه*\n\n";
        $text .= "شما به حداکثر تعداد دانلود مجاز امروز رسیده‌اید.\n\n";
        $text .= "🔄 *فردا دوباره می‌توانید دانلود کنید.*";

        return self::sendMessage($chatId, $text);
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
    public static function formatWaitTime(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'آماده ✅';
        }

        if ($seconds < 60) {
            return "{$seconds} ثانیه";
        }

        if ($seconds < 120) {
            return 'حدود ۱ دقیقه';
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

    /**
     * Validate a chat ID format
     * 
     * @param string $chatId Chat ID to validate
     * @return bool True if valid
     */
    public static function isValidChatId(string $chatId): bool
    {
        // Bale chat IDs are numeric strings (can be negative for groups)
        return preg_match('/^-?\d+$/', $chatId) === 1;
    }

    /**
     * Truncate text for callback data (max 64 bytes)
     * 
     * @param string $text Text to truncate
     * @return string Truncated text
     */
    public static function truncateCallbackData(string $text): string
    {
        if (strlen($text) <= 64) {
            return $text;
        }

        return substr($text, 0, 61) . '...';
    }

    /**
     * Escape Markdown special characters
     * 
     * @param string $text Text to escape
     * @return string Escaped text
     */
    public static function escapeMarkdown(string $text): string
    {
        $specialChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        
        foreach ($specialChars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }

        return $text;
    }
}

// ──── Helper functions for backward compatibility ────

/**
 * Quick send message (backward compatible with gateway.php)
 * 
 * @param string $chatId Chat ID
 * @param string $text Message text
 * @param string|null $replyMarkup JSON-encoded reply markup
 * @return array{http_code: int, body: array|null}
 */
function sendMessage_compat(string $chatId, string $text, ?string $replyMarkup = null): array
{
    $markup = $replyMarkup ? json_decode($replyMarkup, true) : null;
    BaleNotifier::sendMessage($chatId, $text, $markup);
    
    // Return format expected by old gateway.php
    return ['http_code' => 200, 'body' => ['ok' => true]];
}
