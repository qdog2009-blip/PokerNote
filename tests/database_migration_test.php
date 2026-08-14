<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Database.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: pdo_sqlite is not enabled\n");
    exit(2);
}

$databasePath = tempnam(sys_get_temp_dir(), 'pokernote-migration-');
if ($databasePath === false) {
    throw new RuntimeException('Unable to create the temporary database');
}

try {
    $legacy = new PDO('sqlite:' . $databasePath);
    $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $legacy->exec(
        'CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
        CREATE TABLE players (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            initial_buyin REAL DEFAULT 0,
            total_buyin REAL DEFAULT 0,
            final_balance REAL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (session_id) REFERENCES sessions(id)
        );
        CREATE TABLE buyins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            player_id INTEGER NOT NULL,
            amount REAL NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (player_id) REFERENCES players(id)
        )'
    );
    $legacy->exec(
        "INSERT INTO users (email, password) VALUES ('legacy@example.com', 'hash');
         INSERT INTO sessions (user_id, name) VALUES (1, '历史场次')"
    );
    $legacy = null;

    $migrated = Database::connect($databasePath);
    $session = $migrated->query(
        'SELECT s.rake_rate, s.group_id, g.name, g.is_default
         FROM sessions s
         JOIN session_groups g ON g.id = s.group_id
         WHERE s.id = 1'
    )->fetch();

    if ($session === false) {
        throw new RuntimeException('The legacy session was lost during migration');
    }
    if ((float) $session['rake_rate'] !== 0.0) {
        throw new RuntimeException('The legacy session did not receive a zero rake rate');
    }
    if ($session['name'] !== '默认分组' || (int) $session['is_default'] !== 1) {
        throw new RuntimeException('The legacy session was not assigned to the default group');
    }
    $expenseTable = $migrated->query(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'group_pool_expenses'"
    )->fetch();
    if ($expenseTable === false) {
        throw new RuntimeException('The group pool expense table was not created during migration');
    }
    $shareTable = $migrated->query(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'group_shares'"
    )->fetch();
    if ($shareTable === false) {
        throw new RuntimeException('The group share table was not created during migration');
    }

    $migrated = null;
    Database::connect($databasePath);

    echo "PASS: legacy database migration is idempotent\n";
} finally {
    if (is_file($databasePath)) {
        unlink($databasePath);
    }
}
