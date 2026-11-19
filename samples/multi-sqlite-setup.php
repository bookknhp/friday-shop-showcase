<?php
// Sanitized architecture excerpt: Multi-SQLite WAL tuning and cross-database ATTACH DATABASE pattern.

class MultiSqliteConnectionExample
{
    private static ?PDO $chatPdo = null;

    private static function openWalSqlite(string $dbPath): PDO
    {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Concurrency & performance PRAGMAs tuned per connection
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA synchronous = NORMAL;');
        $pdo->exec('PRAGMA busy_timeout = 15000;');
        $pdo->exec('PRAGMA cache_size = -10000;');
        $pdo->exec('PRAGMA mmap_size = 67108864;');
        $pdo->exec('PRAGMA temp_store = MEMORY;');

        return $pdo;
    }

    public static function getChatConnection(string $dataDir): PDO
    {
        if (self::$chatPdo !== null) {
            return self::$chatPdo;
        }

        $pdo = self::openWalSqlite($dataDir . '/app_chat.sqlite');

        // Attach sibling domain databases when a single query needs to join
        // across chat, core, editorial content, and catalog tables.
        $attachments = [
            'core_db' => $dataDir . '/app_core.sqlite',
            'content_db' => $dataDir . '/app_content.sqlite',
            'catalog_db' => $dataDir . '/app_catalog.sqlite',
        ];

        foreach ($attachments as $alias => $path) {
            $escaped = str_replace("'", "''", $path);
            $pdo->exec("ATTACH DATABASE '{$escaped}' AS {$alias};");
        }

        self::$chatPdo = $pdo;
        return $pdo;
    }
}
