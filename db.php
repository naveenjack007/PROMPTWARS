<?php
// cooking-todo-app/db.php

// Disable error display in production, but enable for development/setup.
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database config parameters
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'cooking_todo_db');
define('SQLITE_FILE', __DIR__ . '/cooking_app.sqlite');

function getDBConnection() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $db_type = 'mysql';
    try {
        // Try MySQL first
        $dsn = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
        // Connect to MySQL server first (without database, in case it needs to be created)
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3 // short timeout to fail fast if MySQL is down
        ]);
        
        // Create DB if it doesn't exist, then select it
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `" . DB_NAME . "`");
        
        $_SESSION['db_engine'] = 'MySQL';
    } catch (PDOException $e) {
        // Fall back to SQLite if MySQL fails
        try {
            $dsn = "sqlite:" . SQLITE_FILE;
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            // Enable foreign keys in SQLite
            $pdo->exec("PRAGMA foreign_keys = ON;");
            $_SESSION['db_engine'] = 'SQLite (Fallback)';
        } catch (PDOException $se) {
            die("Database connection failed. Both MySQL and SQLite fallbacks failed: " . $se->getMessage());
        }
    }
    
    return $pdo;
}

// Initialize connection immediately to ensure session state is set
$db = getDBConnection();
?>
