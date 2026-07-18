<?php
// Session Diagnostic Script
header('Content-Type: text/plain');

require_once __DIR__ . '/config.php';

echo "=== BreakFree Session Diagnostics ===\n\n";
echo "Session ID: " . session_id() . "\n";
echo "Session Name: " . session_name() . "\n";
echo "Session Save Path: " . session_save_path() . "\n";

if (!isset($_SESSION['test_counter'])) {
    $_SESSION['test_counter'] = 1;
    echo "Counter initialized to: 1\n";
} else {
    $_SESSION['test_counter']++;
    echo "Counter incremented to: " . $_SESSION['test_counter'] . "\n";
}

echo "\nInstructions: Refresh this page. If the Counter stays at 1 and does not increment, your browser cookies are blocked or the hosting provider is rejecting the session cookie.\n";
?>
