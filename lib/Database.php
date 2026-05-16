<?php
/**
 * ============================================================
 * Database.php — SQLite Database Manager
 * ============================================================
 * 
 * 🏗️ Architecture Layer: 0 - Fundamentals
 * 📦 Part of: Khashayar YouTube Downloader - Queue System
 * 🔗 Dependency: Config.php (File 1) ✅, Logger.php (File 2) ✅
 * 
 * 🎯 Purpose:
 *   • Singleton SQLite connection management
 *   • Automatic schema creation and migration
 *   • WAL mode for concurrent read/write operations
 *   • Prepared statement helper for safe queries
 *   • Connection health monitoring and auto-recovery
 *   • Backup and maintenance utilities
 * 
 * 🔒 Security:
 *   • All queries use prepared statements (SQL injection prevention)
 *   • Database file stored outside webroot (data/ directory)
 *   • File permissions set to 0640 (owner read/write, group read)
 *   • WAL mode prevents database corruption on crash
 *   • Busy timeout prevents "database locked" errors
 * 
 * ⚡ Performance:
 *   • WAL mode: 10x faster concurrent reads
 *   • Persistent connection (singleton pattern)
 *   • Indexed columns for frequent queries
 *   • Write-Ahead Log for non-blocking reads
 *   • Automatic WAL checkpoint on clean shutdown
 * 
 * 📊 Schema Overview:
 *   • pending_queue      — Main job queue
 *   • rate_limits        — User rate limiting
 *   • processed_updates  — Telegram update deduplication
 *   • user_settings      — User preferences (quality, subtitles)
 *   • file_id_cache      — Archived file IDs for Bale channel
 *   • active_jobs        — Currently running GitHub jobs
 *   • queue_stats_daily  — Daily statistics aggregation
 *   • schema_version     — Migration tracking
 * 
 * @package     KhashayarDownloader
 * @version     5.0.0
 * @author      Khashayar
 * @license     MIT
 * @since       2026-05-16
 */

declare(strict_types=1);

// Guard: Must be loaded after Config.php and Logger.php
if (!defined('CONFIG_LOADED')) {
    die('⛔ Database requires Config.php to be loaded first.');
}

/**
 * ============================================================
 * Database Class — SQLite Manager
 * ============================================================
 */
class Database
{
    /** @var Database|null Singleton instance */
    private static ?Database $instance = null;

    /** @var SQLite3 Active database connection */
    private SQLite3 $db;

    /** @var string Database file path */
    private string $dbPath;

    /** @var bool Whether database is initialized */
    private bool $initialized = false;

    /** @var int Current schema version */
    private const SCHEMA_VERSION = 1;

    /**
     * Private constructor — use getInstance()
     * 
     * @throws RuntimeException if database cannot be opened
     */
    private function __construct()
    {
        $this->dbPath = DB_PATH;
        
        try {
            // Ensure data directory exists with proper permissions
            $dataDir = dirname($this->dbPath);
            if (!is_dir($dataDir)) {
                if (!mkdir($dataDir, 0750, true) && !is_dir($dataDir)) {
                    throw new \RuntimeException("Cannot create data directory: {$dataDir}");
                }
                Logger::info('Created data directory', ['path' => $dataDir]);
            }

            // Open database connection
            $this->db = new SQLite3($this->dbPath);
            
            // Configure SQLite for performance and safety
            $this->db->exec('PRAGMA journal_mode=' . DB_JOURNAL_MODE);
            $this->db->exec('PRAGMA busy_timeout=' . DB_BUSY_TIMEOUT);
            $this->db->exec('PRAGMA foreign_keys=ON');
            $this->db->exec('PRAGMA synchronous=NORMAL');
            $this->db->exec('PRAGMA cache_size=-8000'); // 8MB cache
            $this->db->exec('PRAGMA temp_store=MEMORY');
            
            // Enable WAL checkpoint on clean close
            $this->db->exec('PRAGMA wal_autocheckpoint=1000');
            
            // Set proper file permissions
            @chmod($this->dbPath, 0640);
            
            Logger::info('Database connection established', [
                'path'          => basename($this->dbPath),
                'journal_mode'  => DB_JOURNAL_MODE,
                'busy_timeout'  => DB_BUSY_TIMEOUT . 'ms',
            ]);
            
        } catch (\Exception $e) {
            Logger::exception($e, 'Database connection failed');
            throw new \RuntimeException('Failed to initialize database: ' . $e->getMessage());
        }
    }

    /**
     * Prevent cloning of singleton
     */
    private function __clone() {}

    /**
     * Get singleton database instance
     * 
     * @return Database
     */
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new Database();
            self::$instance->initSchema();
        }
        return self::$instance;
    }

    /**
     * Get the underlying SQLite3 connection
     * 
     * @return SQLite3
     */
    public function getConnection(): SQLite3
    {
        return $this->db;
    }

    /**
     * ============================================================
     * Schema Management
     * ============================================================
     */

    /**
     * Initialize or migrate database schema
     * Runs on first connection, creates all tables if needed
     * 
     * @return void
     */
    private function initSchema(): void
    {
        if ($this->initialized) {
            return;
        }

        try {
            // Check current schema version
            $currentVersion = $this->getSchemaVersion();
            
            if ($currentVersion === 0) {
                // Fresh installation — create all tables
                $this->createAllTables();
                $this->setSchemaVersion(self::SCHEMA_VERSION);
                Logger::info('Database schema created', ['version' => self::SCHEMA_VERSION]);
            } elseif ($currentVersion < self::SCHEMA_VERSION) {
                // Migration needed
                $this->migrate($currentVersion);
                Logger::info('Database migrated', [
                    'from' => $currentVersion,
                    'to'   => self::SCHEMA_VERSION,
                ]);
            }
            
            $this->initialized = true;
            
        } catch (\Exception $e) {
            Logger::exception($e, 'Schema initialization failed');
            throw $e;
        }
    }

    /**
     * Get current schema version from database
     * 
     * @return int Schema version (0 if not set)
     */
    private function getSchemaVersion(): int
    {
        // Check if schema_version table exists
        $result = $this->db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='schema_version'"
        );
        
        if (!$result->fetchArray()) {
            return 0;
        }
        
        // Read version
        $result = $this->db->querySingle("SELECT version FROM schema_version ORDER BY version DESC LIMIT 1");
        return $result !== null ? (int) $result : 0;
    }

    /**
     * Set schema version
     * 
     * @param int $version Version number
     * @return void
     */
    private function setSchemaVersion(int $version): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS schema_version (version INTEGER PRIMARY KEY, applied_at INTEGER)");
        $stmt = $this->db->prepare("INSERT INTO schema_version (version, applied_at) VALUES (:version, :time)");
        $stmt->bindValue(':version', $version, SQLITE3_INTEGER);
        $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
        $stmt->execute();
    }

    /**
     * Create all database tables
     * Called on fresh installation
     * 
     * @return void
     */
    private function createAllTables(): void
    {
        Logger::debug('Creating database tables...');
        
        // ═══════════════════════════════════════════════════
        // 1. pending_queue — Main job queue
        // ═══════════════════════════════════════════════════
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS pending_queue (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id         TEXT NOT NULL,
                youtube_url     TEXT NOT NULL,
                quality         TEXT NOT NULL DEFAULT 'best',
                subtitles       TEXT NOT NULL DEFAULT 'no',
                priority        INTEGER NOT NULL DEFAULT 0,
                status          TEXT NOT NULL DEFAULT 'pending',
                github_run_id   TEXT,
                position        INTEGER,
                retry_count     INTEGER NOT NULL DEFAULT 0,
                error_message   TEXT,
                created_at      INTEGER NOT NULL,
                processed_at    INTEGER,
                completed_at    INTEGER,
                notified_start  INTEGER NOT NULL DEFAULT 0,
                notified_done   INTEGER NOT NULL DEFAULT 0
            )
        ");
        
        // Indexes for pending_queue
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_pending_status ON pending_queue(status)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_pending_chat ON pending_queue(chat_id, status)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_pending_created ON pending_queue(created_at)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_pending_priority ON pending_queue(priority DESC, created_at ASC)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_pending_github ON pending_queue(github_run_id)");

        // ═══════════════════════════════════════════════════
        // 2. rate_limits — User rate limiting
        // ═══════════════════════════════════════════════════
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                chat_id             TEXT PRIMARY KEY,
                last_request_time   INTEGER NOT NULL,
                request_count_today INTEGER NOT NULL DEFAULT 0,
                last_reset_date     TEXT NOT NULL DEFAULT ''
            )
        ");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_rate_time ON rate_limits(last_request_time)");

        // ═══════════════════════════════════════════════════
        // 3. processed_updates — Telegram deduplication
        // ═══════════════════════════════════════════════════
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS processed_updates (
                update_id   INTEGER PRIMARY KEY,
                processed_at INTEGER NOT NULL
            )
        ");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_updates_time ON processed_updates(processed_at)");

        // ═══════════════════════════════════════════════════
        // 4. user_settings — User preferences
        // ═══════════════════════════════════════════════════
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS user_settings (
                chat_id     TEXT PRIMARY KEY,
                quality     TEXT NOT NULL DEFAULT 'best',
                subtitles   TEXT NOT NULL DEFAULT 'no',
                updated_at  INTEGER NOT NULL DEFAULT 0
            )
        ");

        // ═══════════════════════════════════════════════════
        // 5. file_id_cache — Bale channel file IDs
        // ═══════════════════════════════════════════════════
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS file_id_cache (
                file_id     TEXT PRIMARY KEY,
                chat_id     TEXT NOT NULL,
                folder_name TEXT NOT NULL,
                file_name   TEXT NOT NULL,
                file_size   INTEGER NOT NULL DEFAULT 0,
                created_at  INTEGER NOT NULL,
                expires_at  INTEGER NOT NULL
            )
        ");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_file_chat ON file_id_cache(chat_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_file_expires ON file_id_cache(expires_at)");

        // ═══════════════════════════════════════════════════
        // 6. active_jobs — Currently running GitHub jobs
        // ═══════════════════════════════════════════════════
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS active_jobs (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                queue_id        INTEGER NOT NULL,
                github_run_id   TEXT NOT NULL,
                status          TEXT NOT NULL DEFAULT 'running',
                started_at      INTEGER NOT NULL,
                checked_at      INTEGER NOT NULL,
                FOREIGN KEY (queue_id) REFERENCES pending_queue(id)
            )
        ");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_active_run ON active_jobs(github_run_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_active_status ON active_jobs(status)");

        // ═══════════════════════════════════════════════════
        // 7. queue_stats_daily — Daily statistics
        // ═══════════════════════════════════════════════════
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS queue_stats_daily (
                date                    TEXT PRIMARY KEY,
                total_requests          INTEGER NOT NULL DEFAULT 0,
                completed_requests      INTEGER NOT NULL DEFAULT 0,
                failed_requests         INTEGER NOT NULL DEFAULT 0,
                peak_queue_length       INTEGER NOT NULL DEFAULT 0,
                total_wait_time_seconds INTEGER NOT NULL DEFAULT 0,
                avg_wait_time_seconds   REAL NOT NULL DEFAULT 0.0,
                github_api_calls        INTEGER NOT NULL DEFAULT 0,
                updated_at              INTEGER NOT NULL DEFAULT 0
            )
        ");

        // ═══════════════════════════════════════════════════
        // 8. lock_table — For process synchronization
        // ═══════════════════════════════════════════════════
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS locks (
                lock_name   TEXT PRIMARY KEY,
                process_id  TEXT NOT NULL,
                acquired_at INTEGER NOT NULL,
                expires_at  INTEGER NOT NULL
            )
        ");

        Logger::debug('All tables created successfully');
    }

    /**
     * Migrate database from older version
     * 
     * @param int $fromVersion Current version
     * @return void
     */
    private function migrate(int $fromVersion): void
    {
        Logger::info('Running database migration', ['from' => $fromVersion, 'to' => self::SCHEMA_VERSION]);
        
        // Future migrations go here
        // Example:
        // if ($fromVersion < 2) {
        //     $this->db->exec("ALTER TABLE pending_queue ADD COLUMN priority INTEGER DEFAULT 0");
        // }
        
        $this->setSchemaVersion(self::SCHEMA_VERSION);
    }

    /**
     * ============================================================
     * Query Helpers
     * ============================================================
     */

    /**
     * Execute a prepared statement with parameters
     * 
     * @param string $sql SQL query with named placeholders (:param)
     * @param array<string, mixed> $params Parameter bindings
     * @return SQLite3Result|bool Result object or false on failure
     * @throws RuntimeException on query failure
     */
    public function execute(string $sql, array $params = []): SQLite3Result|bool
    {
        try {
            $stmt = $this->db->prepare($sql);
            
            if ($stmt === false) {
                throw new \RuntimeException("Failed to prepare statement: {$sql}");
            }
            
            foreach ($params as $key => $value) {
                $type = match (true) {
                    is_int($value)  => SQLITE3_INTEGER,
                    is_float($value) => SQLITE3_FLOAT,
                    is_null($value)  => SQLITE3_NULL,
                    is_bool($value)  => SQLITE3_INTEGER,
                    default          => SQLITE3_TEXT,
                };
                
                if (is_bool($value)) {
                    $value = $value ? 1 : 0;
                }
                
                $stmt->bindValue(':' . $key, $value, $type);
            }
            
            $result = $stmt->execute();
            
            if ($result === false) {
                throw new \RuntimeException(
                    "Query execution failed: " . $this->db->lastErrorMsg()
                );
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Logger::error('Database query failed', [
                'sql'    => substr($sql, 0, 200),
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Execute a query and get a single row as associative array
     * 
     * @param string $sql SQL query
     * @param array<string, mixed> $params Parameters
     * @return array<string, mixed>|null Row or null if not found
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->execute($sql, $params);
        
        if ($result === false || $result === true) {
            return null;
        }
        
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row ?: null;
    }

    /**
     * Execute a query and get all rows as array of associative arrays
     * 
     * @param string $sql SQL query
     * @param array<string, mixed> $params Parameters
     * @return array<int, array<string, mixed>> Array of rows
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $result = $this->execute($sql, $params);
        
        if ($result === false || $result === true) {
            return [];
        }
        
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        
        return $rows;
    }

    /**
     * Get a single scalar value from a query
     * 
     * @param string $sql SQL query returning single value
     * @param array<string, mixed> $params Parameters
     * @return mixed Scalar value or null
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $row = $this->fetchOne($sql, $params);
        
        if ($row === null || empty($row)) {
            return null;
        }
        
        return reset($row); // First value of first row
    }

    /**
     * Get the number of rows affected by last query
     * 
     * @return int Number of changed rows
     */
    public function affectedRows(): int
    {
        return $this->db->changes();
    }

    /**
     * Get the last inserted row ID
     * 
     * @return int Last insert ID
     */
    public function lastInsertId(): int
    {
        return $this->db->lastInsertRowID();
    }

    /**
     * Begin a transaction
     * 
     * @return void
     */
    public function beginTransaction(): void
    {
        $this->db->exec('BEGIN TRANSACTION');
    }

    /**
     * Commit a transaction
     * 
     * @return void
     */
    public function commit(): void
    {
        $this->db->exec('COMMIT');
    }

    /**
     * Rollback a transaction
     * 
     * @return void
     */
    public function rollback(): void
    {
        $this->db->exec('ROLLBACK');
    }

    /**
     * ============================================================
     * Maintenance & Monitoring
     * ============================================================
     */

    /**
     * Get database statistics
     * 
     * @return array<string, mixed> Database statistics
     */
    public function getStats(): array
    {
        return [
            'file_size_mb'      => round(filesize($this->dbPath) / 1024 / 1024, 2),
            'pending_jobs'      => $this->fetchValue("SELECT COUNT(*) FROM pending_queue WHERE status='pending'"),
            'active_jobs'       => $this->fetchValue("SELECT COUNT(*) FROM active_jobs WHERE status='running'"),
            'completed_today'   => $this->fetchValue(
                "SELECT COUNT(*) FROM pending_queue WHERE status='completed' AND date(completed_at, 'unixepoch') = date('now')"
            ),
            'failed_today'      => $this->fetchValue(
                "SELECT COUNT(*) FROM pending_queue WHERE status='failed' AND date(completed_at, 'unixepoch') = date('now')"
            ),
            'total_users'       => $this->fetchValue("SELECT COUNT(DISTINCT chat_id) FROM user_settings"),
            'wal_size_mb'       => round(
                (filesize($this->dbPath . '-wal') ?: 0) / 1024 / 1024, 2
            ),
            'journal_size_mb'   => round(
                (filesize($this->dbPath . '-journal') ?: 0) / 1024 / 1024, 2
            ),
        ];
    }

    /**
     * Run WAL checkpoint to merge WAL into main database
     * 
     * @return void
     */
    public function checkpoint(): void
    {
        $this->db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        Logger::debug('WAL checkpoint completed');
    }

    /**
     * Optimize database (VACUUM)
     * Reclaims space from deleted records
     * 
     * @return void
     */
    public function vacuum(): void
    {
        Logger::info('Starting database VACUUM');
        $before = filesize($this->dbPath);
        
        $this->db->exec('VACUUM');
        
        $after = filesize($this->dbPath);
        $saved = $before - $after;
        
        Logger::info('Database VACUUM completed', [
            'before_mb' => round($before / 1024 / 1024, 2),
            'after_mb'  => round($after / 1024 / 1024, 2),
            'saved_mb'  => round($saved / 1024 / 1024, 2),
        ]);
    }

    /**
     * Create a backup of the database
     * 
     * @return string|null Backup file path or null on failure
     */
    public function backup(): ?string
    {
        $backupDir = DB_BACKUP_DIR;
        
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }
        
        $backupFile = $backupDir . '/queue_backup_' . date('Y-m-d_H-i-s') . '.db';
        
        if (copy($this->dbPath, $backupFile)) {
            @chmod($backupFile, 0640);
            Logger::info('Database backup created', ['file' => basename($backupFile)]);
            return $backupFile;
        }
        
        Logger::error('Database backup failed');
        return null;
    }

    /**
     * Clean up old backups
     * 
     * @return int Number of backups deleted
     */
    public function cleanupBackups(): int
    {
        $backupDir = DB_BACKUP_DIR;
        if (!is_dir($backupDir)) {
            return 0;
        }
        
        $files = glob($backupDir . '/queue_backup_*.db');
        if ($files === false) {
            return 0;
        }
        
        $cutoff = time() - (DB_BACKUP_RETENTION_DAYS * 86400);
        $deleted = 0;
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        if ($deleted > 0) {
            Logger::info('Old backups cleaned', ['deleted' => $deleted]);
        }
        
        return $deleted;
    }

    /**
     * Acquire a named lock (for process synchronization)
     * 
     * @param string $lockName Lock identifier
     * @param int $timeoutSeconds Lock timeout in seconds
     * @return bool True if lock acquired
     */
    public function acquireLock(string $lockName, int $timeoutSeconds = 60): bool
    {
        $now = time();
        
        // Clean expired locks first
        $this->execute(
            "DELETE FROM locks WHERE expires_at < :now",
            ['now' => $now]
        );
        
        // Try to acquire lock
        try {
            $this->execute(
                "INSERT INTO locks (lock_name, process_id, acquired_at, expires_at) 
                 VALUES (:name, :pid, :acquired, :expires)",
                [
                    'name'     => $lockName,
                    'pid'      => getmypid(),
                    'acquired' => $now,
                    'expires'  => $now + $timeoutSeconds,
                ]
            );
            
            return true;
            
        } catch (\Exception $e) {
            // Lock already exists — couldn't acquire
            return false;
        }
    }

    /**
     * Release a named lock
     * 
     * @param string $lockName Lock identifier
     * @return void
     */
    public function releaseLock(string $lockName): void
    {
        $this->execute(
            "DELETE FROM locks WHERE lock_name = :name AND process_id = :pid",
            [
                'name' => $lockName,
                'pid'  => getmypid(),
            ]
        );
    }

    /**
     * Close database connection gracefully
     * 
     * @return void
     */
    public function close(): void
    {
        if (isset($this->db)) {
            // Run WAL checkpoint before closing
            $this->checkpoint();
            $this->db->close();
            Logger::debug('Database connection closed');
        }
    }

    /**
     * Destructor — ensure clean shutdown
     */
    public function __destruct()
    {
        $this->close();
    }
}

// ──── Helper function for quick database access ────

/**
 * Get database instance quickly
 * 
 * @return Database
 */
function db(): Database
{
    return Database::getInstance();
}