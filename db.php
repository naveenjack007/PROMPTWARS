<?php
// Database Management Utility
require_once __DIR__ . '/config.php';

$pdo = null;

try {
    if (DB_TYPE === 'mysql') {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } else {
        // SQLite
        $sqlite_file = __DIR__ . '/database.sqlite';
        $dsn = "sqlite:" . $sqlite_file;
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        // SQLite foreign key enforcement
        $pdo->exec("PRAGMA foreign_keys = ON;");
    }
    
    // Auto-setup database tables if they do not exist
    setup_database_tables($pdo);

} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

/**
 * Automates the database table creation if it's the first run
 */
function setup_database_tables($pdo) {
    // Check if the 'users' table exists. If not, initialize from schema.sql
    try {
        $table_check = $pdo->query("SELECT 1 FROM users LIMIT 1");
        
        // Check if chat_messages exists, if not, migrate it
        try {
            $pdo->query("SELECT 1 FROM chat_messages LIMIT 1");
        } catch (Exception $e) {
            $sql = "CREATE TABLE IF NOT EXISTS chat_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INT NOT NULL,
                sender VARCHAR(10) NOT NULL,
                message TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )";
            if (DB_TYPE === 'mysql') {
                $sql = "CREATE TABLE IF NOT EXISTS chat_messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    sender VARCHAR(10) NOT NULL,
                    message TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )";
            }
            $pdo->exec($sql);
        }
    } catch (Exception $e) {
        // Table doesn't exist, read and execute schema.sql
        $schema_file = __DIR__ . '/schema.sql';
        if (file_exists($schema_file)) {
            $schema_sql = file_get_contents($schema_file);
            
            // For SQLite compatibility, we might need to adjust AUTO_INCREMENT and some data types.
            if (DB_TYPE === 'sqlite') {
                $schema_sql = str_ireplace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $schema_sql);
                $schema_sql = str_ireplace('INT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $schema_sql);
                $schema_sql = str_ireplace('AUTO_INCREMENT', '', $schema_sql);
                $schema_sql = str_ireplace('TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $schema_sql);
                $schema_sql = str_ireplace('TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $schema_sql);
                $schema_sql = str_ireplace('TINYINT(1)', 'INTEGER', $schema_sql);
            }
            
            // Execute the schema statements
            // Split by semicolon, but ignore comments and empty statements
            $queries = explode(';', $schema_sql);
            foreach ($queries as $query) {
                $trimmed = trim($query);
                if (!empty($trimmed)) {
                    $pdo->exec($trimmed);
                }
            }
        }
    }
}
?>
