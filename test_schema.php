<?php
// Schema Inspector Diagnostic Script
header('Content-Type: text/plain');

require_once __DIR__ . '/db.php';

echo "=== BreakFree SQLite Schema Inspector ===\n\n";

try {
    $stmt = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type='table'");
    $tables = $stmt->fetchAll();
    
    foreach ($tables as $t) {
        echo "Table: " . $t['name'] . "\n";
        echo "SQL:\n" . $t['sql'] . "\n";
        echo "----------------------------------------\n\n";
    }
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
