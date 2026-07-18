<?php
// Registration Diagnostic Script
header('Content-Type: text/plain');

require_once __DIR__ . '/db.php';

echo "=== BreakFree Registration Diagnostics ===\n\n";

$username = 'testuser_' . time();
$password = 'password123';
$age = 25;
$gender = 'Male';

echo "Testing registration with:\n";
echo "- Username: {$username}\n";
echo "- Age: {$age}\n";
echo "- Gender: {$gender}\n\n";

try {
    // 1. Try to insert
    echo "Attempting to insert user into 'users' table...\n";
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password, age, gender) VALUES (?, ?, ?, ?)");
    $stmt->execute([$username, $hashed_password, $age, $gender]);
    
    $user_id = $pdo->lastInsertId();
    echo "INSERT SUCCESSFUL! Last Insert ID: " . $user_id . "\n\n";
    
    // 2. Try to verify
    echo "Attempting to query the inserted user...\n";
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if ($user) {
        echo "VERIFICATION SUCCESSFUL! Found user in DB:\n";
        print_r($user);
    } else {
        echo "VERIFICATION FAILED! User with ID {$user_id} was not found in the database after insert.\n";
    }

} catch (PDOException $e) {
    echo "SQL ERROR: " . $e->getMessage() . "\n";
}
?>
