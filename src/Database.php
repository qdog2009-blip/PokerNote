<?php

declare(strict_types=1);

final class Database
{
    public static function connect(string $databasePath): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException(
                'PHP 未启用 pdo_sqlite 扩展，请启用该扩展后重新启动服务'
            );
        }

        if ($databasePath !== ':memory:') {
            $databaseDirectory = dirname($databasePath);
            if (!is_dir($databaseDirectory)) {
                throw new RuntimeException('数据库目录不存在：' . $databaseDirectory);
            }
            if (!is_writable($databaseDirectory)) {
                throw new RuntimeException('数据库目录不可写：' . $databaseDirectory);
            }
        }

        $pdo = new PDO('sqlite:' . $databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::createSchema($pdo);

        return $pdo;
    }

    private static function createSchema(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS auth_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS session_groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                is_default INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, name),
                FOREIGN KEY (user_id) REFERENCES users(id)
            )'
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS group_shares (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_id INTEGER NOT NULL,
                shared_user_id INTEGER NOT NULL,
                permission TEXT NOT NULL CHECK (permission IN ('view', 'input')),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (group_id, shared_user_id),
                FOREIGN KEY (group_id) REFERENCES session_groups(id),
                FOREIGN KEY (shared_user_id) REFERENCES users(id)
            )"
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                group_id INTEGER,
                rake_rate REAL NOT NULL DEFAULT 0,
                final_rake REAL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id),
                FOREIGN KEY (group_id) REFERENCES session_groups(id)
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS group_pool_expenses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_id INTEGER NOT NULL,
                amount REAL NOT NULL CHECK (amount > 0),
                note TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (group_id) REFERENCES session_groups(id)
            )'
        );

        self::addColumnIfMissing($pdo, 'sessions', 'rake_rate', 'REAL NOT NULL DEFAULT 0');
        self::addColumnIfMissing($pdo, 'sessions', 'final_rake', 'REAL');
        self::addColumnIfMissing($pdo, 'sessions', 'group_id', 'INTEGER');
        self::ensureDefaultGroups($pdo);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS players (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                initial_buyin REAL DEFAULT 0,
                total_buyin REAL DEFAULT 0,
                final_balance REAL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS buyins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                player_id INTEGER NOT NULL,
                amount REAL NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (player_id) REFERENCES players(id)
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_tokens_user_id ON auth_tokens(user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_group_id ON sessions(group_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_session_groups_user_id ON session_groups(user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_group_shares_group_id ON group_shares(group_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_group_shares_user_id ON group_shares(shared_user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_group_pool_expenses_group_id ON group_pool_expenses(group_id)');
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_session_groups_default
             ON session_groups(user_id) WHERE is_default = 1'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_players_session_id ON players(session_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_buyins_player_id ON buyins(player_id)');
    }

    private static function addColumnIfMissing(
        PDO $pdo,
        string $table,
        string $column,
        string $definition
    ): void {
        $statement = $pdo->query('PRAGMA table_info(' . $table . ')');
        foreach ($statement->fetchAll() as $field) {
            if ($field['name'] === $column) {
                return;
            }
        }

        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }

    private static function ensureDefaultGroups(PDO $pdo): void
    {
        $pdo->exec(
            "INSERT INTO session_groups (user_id, name, is_default)
             SELECT u.id, '默认分组', 1
             FROM users u
             WHERE NOT EXISTS (
                 SELECT 1 FROM session_groups g
                 WHERE g.user_id = u.id AND g.is_default = 1
             )"
        );

        $pdo->exec(
            'UPDATE sessions
             SET group_id = (
                 SELECT g.id FROM session_groups g
                 WHERE g.user_id = sessions.user_id AND g.is_default = 1
                 LIMIT 1
             )
             WHERE group_id IS NULL'
        );
    }
}
