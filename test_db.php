<?php
// Database Diagnostic Script
header('Content-Type: text/plain');

echo "=== BreakFree Database Diagnostics ===\n\n";

echo "PHP Version: " . PHP_VERSION . "\n";
echo "Operating System: " . PHP_OS . "\n";
echo "Current Directory: " . __DIR__ . "\n";

// 1. Check Directory Write Permissions
$dir = __DIR__;
echo "Is workspace directory writable? " . (is_writable($dir) ? "YES" : "NO") . "\n";

$db_file = $dir . '/database.sqlite';
echo "Does database.sqlite exist? " . (file_exists($db_file) ? "YES" : "NO") . "\n";
if (file_exists($db_file)) {
    echo "Is database.sqlite writable? " . (is_writable($db_file) ? "YES" : "NO") . "\n";
}

// 2. Try Connecting and Creating Tables
echo "\n--- Database Connection Check ---\n";
try {
    require_once __DIR__ . '/config.php';
    echo "Configured DB_TYPE: " . DB_TYPE . "\n";
    
    if (DB_TYPE === 'sqlite') {
        echo "DSN: sqlite:" . $db_file . "\n";
        $pdo = new PDO("sqlite:" . $db_file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        echo "SQLite Connection: SUCCESS\n";
    } else {
        echo "Connecting to MySQL...\n";
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => DB_TYPE === 'sqlite' ? PDO::ERRMODE_EXCEPTION : PDO::ERRMODE_WARNING
        ]);
        echo "MySQL Connection: SUCCESS\n";
    }
    
    // Check tables
    echo "\n--- Table Status ---\n";
    $tables = ['users', 'addictions', 'plans', 'daily_logs', 'settings', 'chat_messages'];
    foreach ($tables as $table) {
        try {
            $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            echo "Table '{$table}': EXISTS\n";
        } catch (Exception $e) {
            echo "Table '{$table}': MISSING (Error: " . $e->getMessage() . ")\n";
        }
    }
    
    // Try running setup
    echo "\n--- Running setup_database_tables() ---\n";
    require_once __DIR__ . '/db.php';
    echo "Running setup...\n";
    setup_database_tables($pdo);
    echo "Setup finished.\n";
    
    // Re-check tables
    echo "\n--- Re-checking Table Status ---\n";
    foreach ($tables as $table) {
        try {
            $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            echo "Table '{$table}': EXISTS\n";
        } catch (Exception $e) {
            echo "Table '{$table}': FAILED (" . $e->getMessage() . ")\n";
        }
    }

} catch (PDOException $e) {
    echo "CONNECTION FAILURE: " . $e->getMessage() . "\n";
}
?>
