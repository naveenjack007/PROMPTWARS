<?php
// Database Reset Tool
header('Content-Type: text/plain');

$db_file = __DIR__ . '/database.sqlite';

echo "=== BreakFree Database Reset Tool ===\n\n";

if (file_exists($db_file)) {
    echo "Found database.sqlite. Attempting to delete...\n";
    if (@unlink($db_file)) {
        echo "SUCCESS: database.sqlite was deleted successfully.\n";
        echo "The next time you refresh index.php, the database will be recreated automatically with the corrected auto-incrementing integer schemas.\n";
    } else {
        echo "FAILURE: Could not delete database.sqlite. Please delete the file database.sqlite in your htdocs directory manually via FTP.\n";
    }
} else {
    echo "No database.sqlite file found. It will be created when you load index.php.\n";
}
?>
