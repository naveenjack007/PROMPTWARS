<?php
// User Authentication Handler (Login/Register)
require_once __DIR__ . '/db.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'register') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $age = intval($_POST['age'] ?? 0);
        $gender = trim($_POST['gender'] ?? '');
        
        if (empty($username) || empty($password) || $age <= 0 || empty($gender)) {
            redirect_with_error('All fields are required.', 'register');
        }
        
        if (strlen($username) < 3) {
            redirect_with_error('Username must be at least 3 characters.', 'register');
        }
        
        if (strlen($password) < 6) {
            redirect_with_error('Password must be at least 6 characters.', 'register');
        }
        
        try {
            // Check if username already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                redirect_with_error('Username already taken.', 'register');
            }
            
            // Hash password and insert user
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, age, gender) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, $hashed_password, $age, $gender]);
            
            // Automatically log the user in after registration
            $user_id = $pdo->lastInsertId();
            $_SESSION['user_id'] = $user_id;
            $_SESSION['username'] = $username;
            $_SESSION['age'] = $age;
            $_SESSION['gender'] = $gender;
            
            // Redirect to setup plan since they are a new user
            header("Location: setup_plan.php");
            exit;
            
        } catch (PDOException $e) {
            redirect_with_error('Registration failed: ' . $e->getMessage(), 'register');
        }
        
    } elseif ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            redirect_with_error('Username and password are required.', 'login');
        }
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // Successful login
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['age'] = $user['age'];
                $_SESSION['gender'] = $user['gender'];
                
                // Check if they already have an active plan
                $stmt = $pdo->prepare("SELECT id FROM plans WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$user['id']]);
                if ($stmt->fetch()) {
                    header("Location: dashboard.php");
                } else {
                    header("Location: setup_plan.php");
                }
                exit;
            } else {
                redirect_with_error('Invalid username or password.', 'login');
            }
            
        } catch (PDOException $e) {
            redirect_with_error('Login failed: ' . $e->getMessage(), 'login');
        }
    }
}

// GET logout
if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit;
}

function redirect_with_error($message, $form_type) {
    $_SESSION['auth_error'] = $message;
    $_SESSION['active_tab'] = $form_type;
    header("Location: index.php");
    exit;
}
?>
